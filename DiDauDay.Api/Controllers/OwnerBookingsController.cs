using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/owner/bookings")]
[Authorize(Roles = "owner")]
public sealed class OwnerBookingsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public OwnerBookingsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Chủ homestay xem danh sách đơn
    [HttpGet]
    public async Task<IActionResult> GetBookings(
        [FromQuery] string? status
    )
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        IQueryable<Booking> query = _db.Bookings
            .AsNoTracking()
            .Where(b => b.Homestay.OwnerId == ownerId);

        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status
                .Trim()
                .ToLowerInvariant();

            query = query.Where(b =>
                b.Status == normalizedStatus
            );
        }

        var bookings = await query
            .OrderByDescending(b => b.CreatedAt)
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.BookingType,
                b.CheckIn,
                b.CheckOut,
                b.GuestCount,
                b.TotalAmount,
                b.Status,
                paymentStatus = b.Payment != null
                    ? b.Payment.Status
                    : null,
                b.CreatedAt,
                homestay = new
                {
                    b.Homestay.Id,
                    b.Homestay.Name,
                    b.Homestay.Slug
                },
                guest = new
                {
                    b.Guest.Id,
                    b.Guest.FullName,
                    b.Guest.Email,
                    b.Guest.Phone
                }
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = bookings.Count,
            bookings
        });
    }

    // Chủ homestay xem chi tiết một đơn
    [HttpGet("{id}")]
    public async Task<IActionResult> GetBooking(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var booking = await _db.Bookings
            .AsNoTracking()
            .Where(b =>
                b.Id == id &&
                b.Homestay.OwnerId == ownerId
            )
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.BookingType,
                b.CheckIn,
                b.CheckOut,
                b.GuestCount,
                b.TotalAmount,
                b.Status,
                b.CreatedAt,
                b.UpdatedAt,
                homestay = new
                {
                    b.Homestay.Id,
                    b.Homestay.Name,
                    b.Homestay.Slug,
                    b.Homestay.Address
                },
                guest = new
                {
                    b.Guest.Id,
                    b.Guest.FullName,
                    b.Guest.Email,
                    b.Guest.Phone
                },
                payment = b.Payment == null
                    ? null
                    : new
                    {
                        b.Payment.Id,
                        b.Payment.TransactionCode,
                        b.Payment.PaymentMethod,
                        b.Payment.Amount,
                        b.Payment.Status,
                        b.Payment.PaidAt
                    }
            })
            .FirstOrDefaultAsync();

        if (booking is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy đơn đặt phòng."
            });
        }

        return Ok(new
        {
            success = true,
            booking
        });
    }

    // Hoàn thành đơn và chia tiền
    [HttpPatch("{id}/complete")]
    public async Task<IActionResult> CompleteBooking(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var booking = await _db.Bookings
            .Include(b => b.Homestay)
            .Include(b => b.Payment)
            .Include(b => b.Settlement)
            .FirstOrDefaultAsync(b =>
                b.Id == id &&
                b.Homestay.OwnerId == ownerId
            );

        if (booking is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy đơn đặt phòng."
            });
        }

        if (
            booking.Status != "confirmed" &&
            booking.Status != "funds_held"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể hoàn thành đơn đã được xác nhận.",
                currentStatus = booking.Status
            });
        }

        if (booking.Payment is null)
        {
            return BadRequest(new
            {
                success = false,
                message = "Đơn này chưa có giao dịch thanh toán."
            });
        }

        if (booking.Payment.Status != "held")
        {
            return BadRequest(new
            {
                success = false,
                message = "Khoản tiền của đơn không ở trạng thái tạm giữ.",
                paymentStatus = booking.Payment.Status
            });
        }

        if (booking.Settlement is not null)
        {
            return Conflict(new
            {
                success = false,
                message = "Đơn này đã được chia tiền trước đó."
            });
        }

        if (DateTime.Now < booking.CheckOut)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chưa đến thời gian trả phòng nên chưa thể hoàn thành đơn.",
                booking.CheckOut
            });
        }

        var admin = await _db.Users
            .FirstOrDefaultAsync(u =>
                u.Role == "admin" &&
                u.Status == "approved"
            );

        if (admin is null)
        {
            return BadRequest(new
            {
                success = false,
                message = "Hệ thống chưa có tài khoản QTV hợp lệ."
            });
        }

        var now = DateTime.Now;
        var grossAmount = booking.TotalAmount;

        var platformFee = decimal.Round(
            grossAmount * 0.10m,
            0,
            MidpointRounding.AwayFromZero
        );

        var ownerAmount = grossAmount - platformFee;

        await using var databaseTransaction =
            await _db.Database.BeginTransactionAsync();

        try
        {
            var ownerWallet = await GetOrCreateWalletAsync(
                ownerId,
                now
            );

            var adminWallet = await GetOrCreateWalletAsync(
                admin.Id,
                now
            );

            // Lưu trước để ví mới nhận được ID
            await _db.SaveChangesAsync();

            ownerWallet.AvailableBalance += ownerAmount;
            ownerWallet.TotalEarned += ownerAmount;
            ownerWallet.UpdatedAt = now;

            adminWallet.AvailableBalance += platformFee;
            adminWallet.TotalEarned += platformFee;
            adminWallet.UpdatedAt = now;

            var settlement = new Settlement
            {
                BookingId = booking.Id,
                OwnerId = ownerId,
                GrossAmount = grossAmount,
                PlatformFee = platformFee,
                OwnerAmount = ownerAmount,
                Status = "completed",
                SettledAt = now,
                CreatedAt = now,
                Booking = booking
            };

            var ownerTransaction = new WalletTransaction
            {
                WalletId = ownerWallet.Id,
                BookingId = booking.Id,
                TransactionType = "owner_income",
                Direction = "credit",
                Amount = ownerAmount,
                BalanceAfter = ownerWallet.AvailableBalance,
                Description =
                    $"Thu nhập từ đơn {booking.BookingCode}",
                Status = "completed",
                CreatedAt = now,
                Wallet = ownerWallet,
                Booking = booking
            };

            var adminTransaction = new WalletTransaction
            {
                WalletId = adminWallet.Id,
                BookingId = booking.Id,
                TransactionType = "platform_fee",
                Direction = "credit",
                Amount = platformFee,
                BalanceAfter = adminWallet.AvailableBalance,
                Description =
                    $"Phí nền tảng của đơn {booking.BookingCode}",
                Status = "completed",
                CreatedAt = now,
                Wallet = adminWallet,
                Booking = booking
            };

            booking.Status = "completed";
            booking.UpdatedAt = now;

            booking.Payment.Status = "settled";

            _db.Settlements.Add(settlement);

            _db.WalletTransactions.AddRange(
                ownerTransaction,
                adminTransaction
            );

            await _db.SaveChangesAsync();
            await databaseTransaction.CommitAsync();

            return Ok(new
            {
                success = true,
                message =
                    "Đã hoàn thành đơn và chia tiền thành công.",
                booking = new
                {
                    booking.Id,
                    booking.BookingCode,
                    booking.Status
                },
                settlement = new
                {
                    settlement.Id,
                    settlement.GrossAmount,
                    settlement.PlatformFee,
                    settlement.OwnerAmount,
                    settlement.Status,
                    settlement.SettledAt
                },
                ownerWallet = new
                {
                    ownerWallet.Id,
                    ownerWallet.AvailableBalance,
                    ownerWallet.TotalEarned
                },
                adminWallet = new
                {
                    adminWallet.Id,
                    adminWallet.AvailableBalance,
                    adminWallet.TotalEarned
                }
            });
        }
        catch
        {
            await databaseTransaction.RollbackAsync();
            throw;
        }
    }

    private async Task<Wallet> GetOrCreateWalletAsync(
        uint userId,
        DateTime now
    )
    {
        var wallet = await _db.Wallets
            .FirstOrDefaultAsync(w => w.UserId == userId);

        if (wallet is not null)
        {
            return wallet;
        }

        wallet = new Wallet
        {
            UserId = userId,
            PendingBalance = 0,
            AvailableBalance = 0,
            TotalEarned = 0,
            UpdatedAt = now
        };

        _db.Wallets.Add(wallet);

        return wallet;
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}

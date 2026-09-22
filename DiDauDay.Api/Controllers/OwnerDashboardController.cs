using System.Security.Claims;
using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/owner/dashboard")]
[Authorize(Roles = "owner")]
public sealed class OwnerDashboardController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public OwnerDashboardController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    [HttpGet]
    public async Task<IActionResult> GetDashboard()
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var totalHomestays =
            await _db.Homestays.CountAsync(h =>
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        var activeHomestays =
            await _db.Homestays.CountAsync(h =>
                h.OwnerId == ownerId &&
                h.Status == "approved" &&
                !h.IsDeleted
            );

        var maintenanceHomestays =
            await _db.Homestays.CountAsync(h =>
                h.OwnerId == ownerId &&
                h.Status == "maintenance" &&
                !h.IsDeleted
            );

        var totalBookings =
            await _db.Bookings.CountAsync(b =>
                b.Homestay.OwnerId == ownerId
            );

        var confirmedBookings =
            await _db.Bookings.CountAsync(b =>
                b.Homestay.OwnerId == ownerId &&
                (
                    b.Status == "confirmed" ||
                    b.Status == "funds_held"
                )
            );

        var completedBookings =
            await _db.Bookings.CountAsync(b =>
                b.Homestay.OwnerId == ownerId &&
                b.Status == "completed"
            );

        var disputedBookings =
            await _db.Bookings.CountAsync(b =>
                b.Homestay.OwnerId == ownerId &&
                b.Status == "disputed"
            );

        var heldMoney = await _db.Payments
            .Where(p =>
                p.Booking.Homestay.OwnerId == ownerId &&
                p.Status == "held"
            )
            .SumAsync(p => (decimal?)p.Amount) ?? 0;

        var totalRevenue = await _db.Settlements
            .Where(s =>
                s.OwnerId == ownerId &&
                s.Status == "completed"
            )
            .SumAsync(s => (decimal?)s.OwnerAmount) ?? 0;

        var wallet = await _db.Wallets
            .AsNoTracking()
            .Where(w => w.UserId == ownerId)
            .Select(w => new
            {
                w.Id,
                w.PendingBalance,
                w.AvailableBalance,
                w.TotalEarned,
                w.UpdatedAt
            })
            .FirstOrDefaultAsync();

        var upcomingBookings = await _db.Bookings
            .AsNoTracking()
            .Where(b =>
                b.Homestay.OwnerId == ownerId &&
                (
                    b.Status == "confirmed" ||
                    b.Status == "funds_held"
                ) &&
                b.CheckIn >= DateTime.Now
            )
            .OrderBy(b => b.CheckIn)
            .Take(5)
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
                homestayName = b.Homestay.Name,
                guestName = b.Guest.FullName,
                guestPhone = b.Guest.Phone
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            overview = new
            {
                totalHomestays,
                activeHomestays,
                maintenanceHomestays,
                totalBookings,
                confirmedBookings,
                completedBookings,
                disputedBookings,
                heldMoney,
                totalRevenue
            },
            wallet = wallet is null
                ? new
                {
                    id = (uint?)null,
                    pendingBalance = 0m,
                    availableBalance = 0m,
                    totalEarned = 0m,
                    updatedAt = (DateTime?)null
                }
                : new
                {
                    id = (uint?)wallet.Id,
                    pendingBalance =
                        wallet.PendingBalance,
                    availableBalance =
                        wallet.AvailableBalance,
                    totalEarned =
                        wallet.TotalEarned,
                    updatedAt =
                        (DateTime?)wallet.UpdatedAt
                },
            upcomingBookings
        });
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}

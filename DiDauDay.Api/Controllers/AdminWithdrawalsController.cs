using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/admin/withdrawals")]
[Authorize(Roles = "admin")]
public sealed class AdminWithdrawalsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public AdminWithdrawalsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // QTV xem các yêu cầu rút tiền
    [HttpGet]
    public async Task<IActionResult> GetWithdrawals(
        [FromQuery] string? status
    )
    {
        IQueryable<WithdrawalRequest> query =
            _db.WithdrawalRequests.AsNoTracking();

        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status
                .Trim()
                .ToLowerInvariant();

            query = query.Where(w =>
                w.Status == normalizedStatus
            );
        }

        var withdrawals = await query
            .OrderByDescending(w => w.RequestedAt)
            .Select(w => new
            {
                w.Id,
                w.Amount,
                w.BankName,
                w.BankAccount,
                w.BankAccountName,
                w.Status,
                w.AdminNote,
                w.RequestedAt,
                w.ProcessedAt,
                w.ProcessedBy,
                processedByName =
                    w.ProcessedByNavigation != null
                        ? w.ProcessedByNavigation.FullName
                        : null,
                owner = new
                {
                    w.Wallet.User.Id,
                    w.Wallet.User.FullName,
                    w.Wallet.User.Email,
                    w.Wallet.User.Phone
                }
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = withdrawals.Count,
            withdrawals
        });
    }

    // QTV duyệt yêu cầu
    [HttpPatch("{id}/approve")]
    public async Task<IActionResult> ApproveWithdrawal(
        uint id,
        [FromBody] ReviewWithdrawalRequest request
    )
    {
        if (!TryGetCurrentUserId(out var adminId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token QTV không hợp lệ."
            });
        }

        var withdrawal = await _db.WithdrawalRequests
            .FirstOrDefaultAsync(w => w.Id == id);

        if (withdrawal is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu rút tiền."
            });
        }

        if (withdrawal.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được xử lý.",
                currentStatus = withdrawal.Status
            });
        }

        withdrawal.Status = "approved";
        withdrawal.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã duyệt yêu cầu rút tiền."
                : request.AdminNote.Trim();

        withdrawal.ProcessedBy = adminId;
        withdrawal.ProcessedAt = DateTime.Now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message =
                "Đã duyệt yêu cầu. QTV có thể tiến hành chuyển khoản.",
            withdrawal = new
            {
                withdrawal.Id,
                withdrawal.Amount,
                withdrawal.Status,
                withdrawal.AdminNote,
                withdrawal.ProcessedBy,
                withdrawal.ProcessedAt
            }
        });
    }

    // QTV xác nhận đã chuyển khoản
    [HttpPatch("{id}/complete")]
    public async Task<IActionResult> CompleteWithdrawal(
        uint id,
        [FromBody] ReviewWithdrawalRequest request
    )
    {
        if (!TryGetCurrentUserId(out var adminId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token QTV không hợp lệ."
            });
        }

        var withdrawal = await _db.WithdrawalRequests
            .FirstOrDefaultAsync(w => w.Id == id);

        if (withdrawal is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu rút tiền."
            });
        }

        if (withdrawal.Status != "approved")
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể hoàn tất yêu cầu đã được QTV duyệt.",
                currentStatus = withdrawal.Status
            });
        }

        var transactionDescription =
            $"Yêu cầu rút tiền #{withdrawal.Id}";

        var walletTransaction =
            await _db.WalletTransactions
                .Where(t =>
                    t.WalletId == withdrawal.WalletId &&
                    t.TransactionType == "withdrawal" &&
                    t.Status == "pending" &&
                    t.Description == transactionDescription
                )
                .OrderByDescending(t => t.CreatedAt)
                .FirstOrDefaultAsync();

        if (walletTransaction is null)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Không tìm thấy giao dịch ví của yêu cầu rút tiền."
            });
        }

        var now = DateTime.Now;

        withdrawal.Status = "completed";
        withdrawal.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã chuyển khoản thành công."
                : request.AdminNote.Trim();

        withdrawal.ProcessedBy = adminId;
        withdrawal.ProcessedAt = now;

        walletTransaction.Status = "completed";

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã hoàn tất yêu cầu rút tiền.",
            withdrawal = new
            {
                withdrawal.Id,
                withdrawal.Amount,
                withdrawal.BankName,
                withdrawal.BankAccount,
                withdrawal.BankAccountName,
                withdrawal.Status,
                withdrawal.AdminNote,
                withdrawal.ProcessedAt
            }
        });
    }

    // QTV từ chối và hoàn tiền lại ví cho chủ homestay
    [HttpPatch("{id}/reject")]
    public async Task<IActionResult> RejectWithdrawal(
        uint id,
        [FromBody] ReviewWithdrawalRequest request
    )
    {
        if (!TryGetCurrentUserId(out var adminId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token QTV không hợp lệ."
            });
        }

        var withdrawal = await _db.WithdrawalRequests
            .Include(w => w.Wallet)
            .FirstOrDefaultAsync(w => w.Id == id);

        if (withdrawal is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu rút tiền."
            });
        }

        if (
            withdrawal.Status != "pending" &&
            withdrawal.Status != "approved"
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này không thể bị từ chối.",
                currentStatus = withdrawal.Status
            });
        }

        var transactionDescription =
            $"Yêu cầu rút tiền #{withdrawal.Id}";

        var withdrawalTransaction =
            await _db.WalletTransactions
                .Where(t =>
                    t.WalletId == withdrawal.WalletId &&
                    t.TransactionType == "withdrawal" &&
                    t.Status == "pending" &&
                    t.Description == transactionDescription
                )
                .OrderByDescending(t => t.CreatedAt)
                .FirstOrDefaultAsync();

        var now = DateTime.Now;

        // Hoàn tiền đã giữ lại vào số dư khả dụng
        withdrawal.Wallet.AvailableBalance +=
            withdrawal.Amount;

        withdrawal.Wallet.UpdatedAt = now;

        withdrawal.Status = "rejected";
        withdrawal.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã từ chối yêu cầu rút tiền."
                : request.AdminNote.Trim();

        withdrawal.ProcessedBy = adminId;
        withdrawal.ProcessedAt = now;

        if (withdrawalTransaction is not null)
        {
            withdrawalTransaction.Status = "failed";
        }

        var refundTransaction = new WalletTransaction
        {
            WalletId = withdrawal.WalletId,
            BookingId = null,
            TransactionType = "adjustment",
            Direction = "credit",
            Amount = withdrawal.Amount,
            BalanceAfter =
                withdrawal.Wallet.AvailableBalance,
            Description =
                $"Hoàn lại yêu cầu rút tiền #{withdrawal.Id}",
            Status = "completed",
            CreatedAt = now,
            Wallet = withdrawal.Wallet
        };

        _db.WalletTransactions.Add(refundTransaction);

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message =
                "Đã từ chối yêu cầu và hoàn tiền lại ví.",
            withdrawal = new
            {
                withdrawal.Id,
                withdrawal.Amount,
                withdrawal.Status,
                withdrawal.AdminNote,
                withdrawal.ProcessedAt
            },
            availableBalance =
                withdrawal.Wallet.AvailableBalance
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

public sealed class ReviewWithdrawalRequest
{
    [StringLength(
        255,
        ErrorMessage = "Ghi chú không được vượt quá 255 ký tự."
    )]
    public string? AdminNote { get; set; }
}

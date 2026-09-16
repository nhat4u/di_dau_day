using System.Security.Claims;
using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/wallet")]
[Authorize(Roles = "owner,admin")]
public sealed class WalletController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public WalletController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Xem số dư ví của tài khoản đang đăng nhập
    [HttpGet]
    public async Task<IActionResult> GetMyWallet()
    {
        if (!TryGetCurrentUserId(out var userId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var wallet = await _db.Wallets
            .AsNoTracking()
            .Where(w => w.UserId == userId)
            .Select(w => new
            {
                w.Id,
                w.UserId,
                w.PendingBalance,
                w.AvailableBalance,
                w.TotalEarned,
                w.UpdatedAt
            })
            .FirstOrDefaultAsync();

        if (wallet is null)
        {
            return Ok(new
            {
                success = true,
                message = "Ví chưa phát sinh giao dịch.",
                wallet = new
                {
                    id = (uint?)null,
                    userId,
                    pendingBalance = 0m,
                    availableBalance = 0m,
                    totalEarned = 0m,
                    updatedAt = (DateTime?)null
                }
            });
        }

        return Ok(new
        {
            success = true,
            wallet
        });
    }

    // Xem lịch sử giao dịch ví
    [HttpGet("transactions")]
    public async Task<IActionResult> GetMyTransactions(
        [FromQuery] int limit = 50
    )
    {
        if (!TryGetCurrentUserId(out var userId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        limit = Math.Clamp(limit, 1, 100);

        var transactions = await _db.WalletTransactions
            .AsNoTracking()
            .Where(t => t.Wallet.UserId == userId)
            .OrderByDescending(t => t.CreatedAt)
            .Take(limit)
            .Select(t => new
            {
                t.Id,
                t.WalletId,
                t.BookingId,
                bookingCode = t.Booking != null
                    ? t.Booking.BookingCode
                    : null,
                t.TransactionType,
                t.Direction,
                t.Amount,
                t.BalanceAfter,
                t.Description,
                t.Status,
                t.CreatedAt
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = transactions.Count,
            transactions
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
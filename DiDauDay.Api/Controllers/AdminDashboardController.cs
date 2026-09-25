using System.Security.Claims;
using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/admin/dashboard")]
[Authorize(Roles = "admin")]
public sealed class AdminDashboardController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public AdminDashboardController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    [HttpGet]
    public async Task<IActionResult> GetDashboard()
    {
        if (!TryGetCurrentUserId(out var adminId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token QTV không hợp lệ."
            });
        }

        var pendingOwners = await _db.Users.CountAsync(u =>
            u.Role == "owner" &&
            u.Status == "pending"
        );

        var approvedOwners = await _db.Users.CountAsync(u =>
            u.Role == "owner" &&
            u.Status == "approved"
        );

        var approvedHomestays =
            await _db.Homestays.CountAsync(h =>
                h.Status == "approved" &&
                !h.IsDeleted
            );

        var totalGuests = await _db.Users.CountAsync(u =>
            u.Role == "guest" &&
            u.Status == "approved"
        );

        var totalBookings =
            await _db.Bookings.CountAsync();

        var confirmedBookings =
            await _db.Bookings.CountAsync(b =>
                b.Status == "confirmed" ||
                b.Status == "funds_held"
            );

        var completedBookings =
            await _db.Bookings.CountAsync(b =>
                b.Status == "completed"
            );

        var heldMoney = await _db.Payments
            .Where(p => p.Status == "held")
            .SumAsync(p => (decimal?)p.Amount) ?? 0;

        var totalRevenue = await _db.Settlements
            .Where(s => s.Status == "completed")
            .SumAsync(s => (decimal?)s.GrossAmount) ?? 0;

        var platformRevenue = await _db.Settlements
            .Where(s => s.Status == "completed")
            .SumAsync(s => (decimal?)s.PlatformFee) ?? 0;

        var pendingWithdrawals =
            await _db.WithdrawalRequests.CountAsync(w =>
                w.Status == "pending"
            );

        var pendingRefunds =
            await _db.RefundRequests.CountAsync(r =>
                r.Status == "pending"
            );

        var pendingProfileChanges =
            await _db.ProfileChangeRequests.CountAsync(r =>
                r.Status == "pending"
            );

        var adminWallet = await _db.Wallets
            .AsNoTracking()
            .Where(w => w.UserId == adminId)
            .Select(w => new
            {
                w.Id,
                w.PendingBalance,
                w.AvailableBalance,
                w.TotalEarned,
                w.UpdatedAt
            })
            .FirstOrDefaultAsync();

        var recentBookings = await _db.Bookings
            .AsNoTracking()
            .OrderByDescending(b => b.CreatedAt)
            .Take(5)
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.TotalAmount,
                b.Status,
                b.CreatedAt,
                homestayName = b.Homestay.Name,
                guestName = b.Guest.FullName
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            overview = new
            {
                pendingOwners,
                approvedOwners,
                approvedHomestays,
                totalGuests,
                totalBookings,
                confirmedBookings,
                completedBookings,
                heldMoney,
                totalRevenue,
                platformRevenue
            },
            pendingActions = new
            {
                pendingWithdrawals,
                pendingRefunds,
                pendingProfileChanges
            },
            wallet = adminWallet is null
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
                    id = (uint?)adminWallet.Id,
                    pendingBalance =
                        adminWallet.PendingBalance,
                    availableBalance =
                        adminWallet.AvailableBalance,
                    totalEarned =
                        adminWallet.TotalEarned,
                    updatedAt =
                        (DateTime?)adminWallet.UpdatedAt
                },
            recentBookings
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

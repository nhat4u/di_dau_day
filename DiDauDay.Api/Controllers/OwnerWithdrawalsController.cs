using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/owner/withdrawals")]
[Authorize(Roles = "owner")]
public sealed class OwnerWithdrawalsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public OwnerWithdrawalsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // Chủ homestay gửi yêu cầu rút tiền
    [HttpPost]
    public async Task<IActionResult> CreateWithdrawal(
        [FromBody] CreateWithdrawalRequest request
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

        if (request.Amount <= 0)
        {
            return BadRequest(new
            {
                success = false,
                message = "Số tiền rút phải lớn hơn 0."
            });
        }

        var wallet = await _db.Wallets
            .FirstOrDefaultAsync(w => w.UserId == ownerId);

        if (wallet is null)
        {
            return BadRequest(new
            {
                success = false,
                message = "Tài khoản chưa có ví."
            });
        }

        var ownerProfile = await _db.OwnerProfiles
            .AsNoTracking()
            .FirstOrDefaultAsync(p => p.UserId == ownerId);

        if (ownerProfile is null)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Bạn phải hoàn thành hồ sơ chủ homestay trước khi rút tiền."
            });
        }

        var hasPendingRequest = await _db.WithdrawalRequests
            .AnyAsync(w =>
                w.WalletId == wallet.Id &&
                w.Status == "pending"
            );

        if (hasPendingRequest)
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Bạn đang có một yêu cầu rút tiền chờ QTV xử lý."
            });
        }

        if (request.Amount > wallet.AvailableBalance)
        {
            return BadRequest(new
            {
                success = false,
                message = "Số dư khả dụng không đủ.",
                availableBalance = wallet.AvailableBalance
            });
        }

        var now = DateTime.Now;

        await using var databaseTransaction =
            await _db.Database.BeginTransactionAsync();

        try
        {
            wallet.AvailableBalance -= request.Amount;
            wallet.UpdatedAt = now;

            var withdrawal = new WithdrawalRequest
            {
                WalletId = wallet.Id,
                Amount = request.Amount,
                BankName = ownerProfile.BankName,
                BankAccount = ownerProfile.BankAccount,
                BankAccountName = ownerProfile.BankAccountName,
                Status = "pending",
                AdminNote = null,
                ProcessedBy = null,
                RequestedAt = now,
                ProcessedAt = null,
                Wallet = wallet
            };

            _db.WithdrawalRequests.Add(withdrawal);
            await _db.SaveChangesAsync();

            var walletTransaction = new WalletTransaction
            {
                WalletId = wallet.Id,
                BookingId = null,
                TransactionType = "withdrawal",
                Direction = "debit",
                Amount = request.Amount,
                BalanceAfter = wallet.AvailableBalance,
                Description =
                    $"Yêu cầu rút tiền #{withdrawal.Id}",
                Status = "pending",
                CreatedAt = now,
                Wallet = wallet
            };

            _db.WalletTransactions.Add(walletTransaction);

            await _db.SaveChangesAsync();
            await databaseTransaction.CommitAsync();

            return StatusCode(201, new
            {
                success = true,
                message =
                    "Đã gửi yêu cầu rút tiền. Vui lòng chờ QTV xử lý.",
                withdrawal = new
                {
                    withdrawal.Id,
                    withdrawal.Amount,
                    withdrawal.BankName,
                    withdrawal.BankAccount,
                    withdrawal.BankAccountName,
                    withdrawal.Status,
                    withdrawal.RequestedAt
                },
                remainingBalance = wallet.AvailableBalance
            });
        }
        catch
        {
            await databaseTransaction.RollbackAsync();
            throw;
        }
    }

    // Chủ homestay xem các yêu cầu rút tiền của mình
    [HttpGet]
    public async Task<IActionResult> GetMyWithdrawals()
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var withdrawals = await _db.WithdrawalRequests
            .AsNoTracking()
            .Where(w => w.Wallet.UserId == ownerId)
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
                w.ProcessedAt
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = withdrawals.Count,
            withdrawals
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

public sealed class CreateWithdrawalRequest
{
    [Range(
        typeof(decimal),
        "10000",
        "1000000000",
        ErrorMessage = "Số tiền rút tối thiểu là 10.000 đồng."
    )]
    public decimal Amount { get; set; }
}
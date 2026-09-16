using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Authorize(Roles = "owner")]
[Route("api/owner/profile")]
public class OwnerProfilesController : ControllerBase
{
    private readonly DiDauDayDbContext _context;

    public OwnerProfilesController(DiDauDayDbContext context)
    {
        _context = context;
    }

    // Xem hồ sơ chủ homestay
    [HttpGet]
    public async Task<IActionResult> GetProfile()
    {
        if (!TryGetCurrentUserId(out var userId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var profile = await _context.OwnerProfiles
            .AsNoTracking()
            .Where(p => p.UserId == userId)
            .Select(p => new
            {
                p.Id,
                p.UserId,
                p.CitizenId,
                p.Address,
                p.BankName,
                p.BankAccount,
                p.BankAccountName,
                p.CreatedAt
            })
            .FirstOrDefaultAsync();

        if (profile == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Bạn chưa hoàn thành hồ sơ chủ homestay."
            });
        }

        return Ok(new
        {
            success = true,
            profile
        });
    }

    // Tạo hồ sơ chủ homestay một lần duy nhất
    [HttpPost]
    public async Task<IActionResult> CreateProfile(
        [FromBody] CreateOwnerProfileRequest request
    )
    {
        if (!TryGetCurrentUserId(out var userId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var profileExists = await _context.OwnerProfiles
            .AnyAsync(p => p.UserId == userId);

        if (profileExists)
        {
            return Conflict(new
            {
                success = false,
                message = "Bạn đã tạo hồ sơ. Muốn thay đổi thông tin, vui lòng liên hệ QTV."
            });
        }

        var citizenId = request.CitizenId.Trim();

        var citizenIdExists = await _context.OwnerProfiles
            .AnyAsync(p => p.CitizenId == citizenId);

        if (citizenIdExists)
        {
            return Conflict(new
            {
                success = false,
                message = "Số CCCD/CMND đã được sử dụng."
            });
        }

        var profile = new OwnerProfile
        {
            UserId = userId,
            CitizenId = citizenId,
            Address = request.Address.Trim(),
            BankName = request.BankName.Trim(),
            BankAccount = request.BankAccount.Trim(),
            BankAccountName = request.BankAccountName.Trim(),
            CreatedAt = DateTime.Now
        };

        _context.OwnerProfiles.Add(profile);
        await _context.SaveChangesAsync();

        return StatusCode(StatusCodes.Status201Created, new
        {
            success = true,
            message = "Tạo hồ sơ chủ homestay thành công.",
            profile = new
            {
                profile.Id,
                profile.UserId,
                profile.CitizenId,
                profile.Address,
                profile.BankName,
                profile.BankAccount,
                profile.BankAccountName,
                profile.CreatedAt
            }
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

public sealed class CreateOwnerProfileRequest
{
    [Required(ErrorMessage = "Vui lòng nhập số CCCD/CMND.")]
    [RegularExpression(
        @"^\d{9,12}$",
        ErrorMessage = "CCCD/CMND phải gồm từ 9 đến 12 chữ số."
    )]
    public string CitizenId { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập địa chỉ.")]
    [StringLength(
        255,
        MinimumLength = 5,
        ErrorMessage = "Địa chỉ phải có từ 5 đến 255 ký tự."
    )]
    public string Address { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập tên ngân hàng.")]
    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage = "Tên ngân hàng phải có từ 2 đến 100 ký tự."
    )]
    public string BankName { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập số tài khoản.")]
    [RegularExpression(
        @"^\d{6,30}$",
        ErrorMessage = "Số tài khoản phải gồm từ 6 đến 30 chữ số."
    )]
    public string BankAccount { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập tên chủ tài khoản.")]
    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage = "Tên chủ tài khoản phải có từ 2 đến 100 ký tự."
    )]
    public string BankAccountName { get; set; } = string.Empty;
}
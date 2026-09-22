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

    // Chủ homestay xem lại toàn bộ thông tin đã đăng ký.
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

        var owner = await _context.Users
            .AsNoTracking()
            .Where(u =>
                u.Id == userId &&
                u.Role == "owner"
            )
            .Select(u => new
            {
                account = new
                {
                    u.Id,
                    u.FullName,
                    u.Email,
                    u.Phone,
                    u.Role,
                    u.Status,
                    u.CreatedAt,
                    u.UpdatedAt
                },
                profile = u.OwnerProfile == null
                    ? null
                    : new
                    {
                        u.OwnerProfile.Id,
                        u.OwnerProfile.UserId,
                        u.OwnerProfile.CitizenId,
                        u.OwnerProfile.Address,
                        u.OwnerProfile.BankName,
                        u.OwnerProfile.BankAccount,
                        u.OwnerProfile.BankAccountName,
                        u.OwnerProfile.CreatedAt
                    },
                hasBankAccount =
                    u.OwnerProfile != null &&
                    u.OwnerProfile.BankName != null &&
                    u.OwnerProfile.BankAccount != null &&
                    u.OwnerProfile.BankAccountName != null
            })
            .FirstOrDefaultAsync();

        if (owner == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy tài khoản chủ homestay."
            });
        }

        // Cột created_at/updated_at trong DB lưu bằng DateTime.UtcNow (giờ UTC),
        // nhưng EF Core đọc lên với Kind = Unspecified nên khi serialize sang JSON
        // sẽ thiếu hậu tố "Z". Trình duyệt hiểu nhầm chuỗi đó là giờ địa phương
        // thay vì UTC, dẫn tới hiển thị sai lệch (thường lệch 7 tiếng so với
        // giờ Việt Nam thực tế). Đánh dấu rõ Kind = Utc trước khi trả JSON để
        // frontend tự quy đổi đúng sang giờ máy người dùng.
        return Ok(new
        {
            success = true,
            account = new
            {
                owner.account.Id,
                owner.account.FullName,
                owner.account.Email,
                owner.account.Phone,
                owner.account.Role,
                owner.account.Status,
                CreatedAt = DateTime.SpecifyKind(
                    owner.account.CreatedAt,
                    DateTimeKind.Utc
                ),
                UpdatedAt = DateTime.SpecifyKind(
                    owner.account.UpdatedAt,
                    DateTimeKind.Utc
                )
            },
            profile = owner.profile == null
                ? null
                : new
                {
                    owner.profile.Id,
                    owner.profile.UserId,
                    owner.profile.CitizenId,
                    owner.profile.Address,
                    owner.profile.BankName,
                    owner.profile.BankAccount,
                    owner.profile.BankAccountName,
                    CreatedAt = DateTime.SpecifyKind(
                        owner.profile.CreatedAt,
                        DateTimeKind.Utc
                    )
                },
            owner.hasBankAccount
        });
    }

    // Chỉ dành cho dữ liệu cũ chưa có owner_profiles.
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

        bool profileExists = await _context.OwnerProfiles
            .AnyAsync(p => p.UserId == userId);

        if (profileExists)
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Hồ sơ đã tồn tại. Hãy gửi yêu cầu nếu cần thay đổi thông tin."
            });
        }

        string citizenId = request.CitizenId.Trim();

        bool citizenIdExists = await _context.OwnerProfiles
            .AnyAsync(p => p.CitizenId == citizenId);

        if (citizenIdExists)
        {
            return Conflict(new
            {
                success = false,
                message = "Số CCCD/CMND đã được sử dụng."
            });
        }

        bool hasAnyBankField =
            !string.IsNullOrWhiteSpace(request.BankName) ||
            !string.IsNullOrWhiteSpace(request.BankAccount) ||
            !string.IsNullOrWhiteSpace(request.BankAccountName);

        bool hasAllBankFields =
            !string.IsNullOrWhiteSpace(request.BankName) &&
            !string.IsNullOrWhiteSpace(request.BankAccount) &&
            !string.IsNullOrWhiteSpace(request.BankAccountName);

        if (hasAnyBankField && !hasAllBankFields)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Nếu thêm tài khoản ngân hàng, vui lòng nhập đủ ba thông tin."
            });
        }

        var profile = new OwnerProfile
        {
            UserId = userId,
            CitizenId = citizenId,
            Address = request.Address.Trim(),
            BankName = request.BankName?.Trim(),
            BankAccount = request.BankAccount?.Trim(),
            BankAccountName = request.BankAccountName?.Trim(),
            CreatedAt = DateTime.UtcNow
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

    // Tài khoản ngân hàng được bổ sung trực tiếp đúng một lần.
    // Sau đó mọi thay đổi phải gửi QTV duyệt.
    [HttpPatch("bank")]
    public async Task<IActionResult> AddBankAccount(
        [FromBody] AddBankAccountRequest request
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

        var profile = await _context.OwnerProfiles
            .FirstOrDefaultAsync(p => p.UserId == userId);

        if (profile == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy hồ sơ chủ homestay."
            });
        }

        bool bankWasAdded =
            !string.IsNullOrWhiteSpace(profile.BankName) ||
            !string.IsNullOrWhiteSpace(profile.BankAccount) ||
            !string.IsNullOrWhiteSpace(profile.BankAccountName);

        if (bankWasAdded)
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Tài khoản ngân hàng đã được thêm. Muốn thay đổi, hãy gửi yêu cầu để QTV duyệt."
            });
        }

        profile.BankName = request.BankName.Trim();
        profile.BankAccount = request.BankAccount.Trim();
        profile.BankAccountName = request.BankAccountName.Trim();

        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã bổ sung tài khoản ngân hàng.",
            bankAccount = new
            {
                profile.BankName,
                profile.BankAccount,
                profile.BankAccountName
            }
        });
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        string? userIdValue = User.FindFirstValue(
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

    [StringLength(100, MinimumLength = 2)]
    public string? BankName { get; set; }

    [RegularExpression(
        @"^\d{6,30}$",
        ErrorMessage = "Số tài khoản phải gồm từ 6 đến 30 chữ số."
    )]
    public string? BankAccount { get; set; }

    [StringLength(100, MinimumLength = 2)]
    public string? BankAccountName { get; set; }
}

public sealed class AddBankAccountRequest
{
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

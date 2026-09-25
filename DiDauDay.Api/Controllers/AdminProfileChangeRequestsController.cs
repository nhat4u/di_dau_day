using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/admin/profile-change-requests")]
[Authorize(Roles = "admin")]
public sealed class AdminProfileChangeRequestsController
    : ControllerBase
{
    private static readonly string[] AllowedStatuses =
    [
        "pending",
        "approved",
        "rejected",
        "completed"
    ];

    private readonly DiDauDayDbContext _db;

    public AdminProfileChangeRequestsController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    [HttpGet]
    public async Task<IActionResult> GetRequests(
        [FromQuery] string? status
    )
    {
        string? normalizedStatus = status?
            .Trim()
            .ToLowerInvariant();

        if (
            !string.IsNullOrWhiteSpace(normalizedStatus) &&
            !AllowedStatuses.Contains(normalizedStatus)
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Trạng thái yêu cầu không hợp lệ."
            });
        }

        var query = _db.ProfileChangeRequests
            .AsNoTracking()
            .AsQueryable();

        if (!string.IsNullOrWhiteSpace(normalizedStatus))
        {
            query = query.Where(r => r.Status == normalizedStatus);
        }

        var rows = await query
            .OrderBy(r => r.Status == "pending" ? 0 : 1)
            .ThenByDescending(r => r.CreatedAt)
            .Select(r => new
            {
                r.Id,
                r.OwnerId,
                r.Reason,
                r.RequestedInformation,
                r.Status,
                r.AdminNote,
                r.CreatedAt,
                r.ProcessedAt,
                r.ProcessedBy,
                processedByName =
                    r.ProcessedByNavigation != null
                        ? r.ProcessedByNavigation.FullName
                        : null,
                owner = new
                {
                    r.Owner.Id,
                    r.Owner.FullName,
                    r.Owner.Email,
                    r.Owner.Phone
                },
                currentProfile =
                    r.Owner.OwnerProfile == null
                        ? null
                        : new
                        {
                            r.Owner.OwnerProfile.CitizenId,
                            r.Owner.OwnerProfile.Address,
                            r.Owner.OwnerProfile.BankName,
                            r.Owner.OwnerProfile.BankAccount,
                            r.Owner.OwnerProfile.BankAccountName
                        }
            })
            .ToListAsync();

        var requests = rows.Select(r => new
        {
            r.Id,
            r.OwnerId,
            r.Reason,
            requestedChanges =
                OwnerProfileChangeRequestsController
                    .DeserializeChanges(r.RequestedInformation),
            r.RequestedInformation,
            r.Status,
            r.AdminNote,
            r.CreatedAt,
            r.ProcessedAt,
            r.ProcessedBy,
            r.processedByName,
            r.owner,
            r.currentProfile
        });

        return Ok(new
        {
            success = true,
            total = rows.Count,
            requests
        });
    }

    // Với yêu cầu mới, thao tác duyệt đồng thời áp dụng dữ liệu và hoàn tất.
    [HttpPatch("{id}/approve")]
    public async Task<IActionResult> ApproveRequest(
        uint id,
        [FromBody] ReviewProfileChangeRequestDto request
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

        var changeRequest = await _db.ProfileChangeRequests
            .Include(r => r.Owner)
            .ThenInclude(u => u.OwnerProfile)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu sửa hồ sơ."
            });
        }

        if (changeRequest.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được xử lý.",
                currentStatus = changeRequest.Status
            });
        }

        if (changeRequest.Owner.OwnerProfile == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy hồ sơ chủ homestay."
            });
        }

        var changes = OwnerProfileChangeRequestsController
            .DeserializeChanges(
                changeRequest.RequestedInformation
            );

        if (changes == null)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Đây là yêu cầu dữ liệu cũ. Hãy dùng thao tác hoàn tất thủ công hoặc yêu cầu chủ homestay gửi lại."
            });
        }

        string? validationError = await ValidateUniqueValues(
            changes,
            changeRequest.OwnerId
        );

        if (validationError != null)
        {
            return Conflict(new
            {
                success = false,
                message = validationError
            });
        }

        ApplyChanges(
            changeRequest.Owner,
            changeRequest.Owner.OwnerProfile,
            changes
        );

        var now = DateTime.UtcNow;
        changeRequest.Owner.UpdatedAt = now;
        changeRequest.Status = "completed";
        changeRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã duyệt và cập nhật hồ sơ."
                : request.AdminNote.Trim();
        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã duyệt và cập nhật hồ sơ chủ homestay.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.OwnerId,
                changeRequest.Status,
                changeRequest.AdminNote,
                changeRequest.ProcessedAt
            }
        });
    }

    [HttpPatch("{id}/reject")]
    public async Task<IActionResult> RejectRequest(
        uint id,
        [FromBody] ReviewProfileChangeRequestDto request
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

        var changeRequest = await _db.ProfileChangeRequests
            .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu sửa hồ sơ."
            });
        }

        if (
            changeRequest.Status != "pending" &&
            changeRequest.Status != "approved"
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này không thể bị từ chối.",
                currentStatus = changeRequest.Status
            });
        }

        if (string.IsNullOrWhiteSpace(request.AdminNote))
        {
            return BadRequest(new
            {
                success = false,
                message = "Vui lòng nhập lý do từ chối."
            });
        }

        changeRequest.Status = "rejected";
        changeRequest.AdminNote = request.AdminNote.Trim();
        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = DateTime.UtcNow;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã từ chối yêu cầu sửa hồ sơ.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.Status,
                changeRequest.AdminNote,
                changeRequest.ProcessedAt
            }
        });
    }

    // Giữ endpoint này để xử lý các bản ghi cũ từng lưu dạng văn bản.
    [HttpPatch("{id}/complete")]
    public async Task<IActionResult> CompleteLegacyRequest(
        uint id,
        [FromBody] ApplyProfileChangesDto request
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

        var changeRequest = await _db.ProfileChangeRequests
            .Include(r => r.Owner)
            .ThenInclude(u => u.OwnerProfile)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest?.Owner.OwnerProfile == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu hoặc hồ sơ chủ homestay."
            });
        }

        if (changeRequest.Status != "approved")
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể hoàn tất thủ công yêu cầu cũ đã được duyệt."
            });
        }

        var changes = request.ToChanges();

        if (!HasAnyChange(changes))
        {
            return BadRequest(new
            {
                success = false,
                message = "QTV phải nhập ít nhất một thông tin cần thay đổi."
            });
        }

        string? validationError = await ValidateUniqueValues(
            changes,
            changeRequest.OwnerId
        );

        if (validationError != null)
        {
            return Conflict(new
            {
                success = false,
                message = validationError
            });
        }

        ApplyChanges(
            changeRequest.Owner,
            changeRequest.Owner.OwnerProfile,
            changes
        );

        var now = DateTime.UtcNow;
        changeRequest.Owner.UpdatedAt = now;
        changeRequest.Status = "completed";
        changeRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã cập nhật hồ sơ."
                : request.AdminNote.Trim();
        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã cập nhật hồ sơ chủ homestay."
        });
    }

    private async Task<string?> ValidateUniqueValues(
        OwnerProfileChangesDto changes,
        uint ownerId
    )
    {
        if (
            changes.Email != null &&
            await _db.Users.AnyAsync(u =>
                u.Id != ownerId &&
                u.Email == changes.Email
            )
        )
        {
            return "Email mới đã được tài khoản khác sử dụng.";
        }

        if (
            changes.Phone != null &&
            await _db.Users.AnyAsync(u =>
                u.Id != ownerId &&
                u.Phone == changes.Phone
            )
        )
        {
            return "Số điện thoại mới đã được tài khoản khác sử dụng.";
        }

        if (
            changes.CitizenId != null &&
            await _db.OwnerProfiles.AnyAsync(p =>
                p.UserId != ownerId &&
                p.CitizenId == changes.CitizenId
            )
        )
        {
            return "Số CCCD/CMND mới đã được sử dụng.";
        }

        return null;
    }

    private static void ApplyChanges(
        User owner,
        OwnerProfile profile,
        OwnerProfileChangesDto changes
    )
    {
        if (changes.FullName != null)
        {
            owner.FullName = changes.FullName.Trim();
        }

        if (changes.Email != null)
        {
            owner.Email = changes.Email.Trim().ToLowerInvariant();
        }

        if (changes.Phone != null)
        {
            owner.Phone = changes.Phone.Trim();
        }

        if (changes.CitizenId != null)
        {
            profile.CitizenId = changes.CitizenId.Trim();
        }

        if (changes.Address != null)
        {
            profile.Address = changes.Address.Trim();
        }

        if (changes.BankName != null)
        {
            profile.BankName = changes.BankName.Trim();
        }

        if (changes.BankAccount != null)
        {
            profile.BankAccount = changes.BankAccount.Trim();
        }

        if (changes.BankAccountName != null)
        {
            profile.BankAccountName = changes.BankAccountName.Trim();
        }
    }

    private static bool HasAnyChange(OwnerProfileChangesDto changes)
    {
        return
            changes.FullName != null ||
            changes.Email != null ||
            changes.Phone != null ||
            changes.CitizenId != null ||
            changes.Address != null ||
            changes.BankName != null ||
            changes.BankAccount != null ||
            changes.BankAccountName != null;
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        string? userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}

public sealed class ReviewProfileChangeRequestDto
{
    [StringLength(
        500,
        ErrorMessage = "Ghi chú không được vượt quá 500 ký tự."
    )]
    public string? AdminNote { get; set; }
}

public sealed class ApplyProfileChangesDto
{
    [StringLength(100, MinimumLength = 2)]
    public string? FullName { get; set; }

    [EmailAddress]
    public string? Email { get; set; }

    [RegularExpression(@"^0\d{9}$")]
    public string? Phone { get; set; }

    [RegularExpression(@"^\d{9,12}$")]
    public string? CitizenId { get; set; }

    [StringLength(255, MinimumLength = 5)]
    public string? Address { get; set; }

    [StringLength(100, MinimumLength = 2)]
    public string? BankName { get; set; }

    [RegularExpression(@"^\d{6,30}$")]
    public string? BankAccount { get; set; }

    [StringLength(100, MinimumLength = 2)]
    public string? BankAccountName { get; set; }

    [StringLength(500)]
    public string? AdminNote { get; set; }

    public OwnerProfileChangesDto ToChanges()
    {
        return new OwnerProfileChangesDto
        {
            FullName = Normalize(FullName),
            Email = Normalize(Email)?.ToLowerInvariant(),
            Phone = Normalize(Phone),
            CitizenId = Normalize(CitizenId),
            Address = Normalize(Address),
            BankName = Normalize(BankName),
            BankAccount = Normalize(BankAccount),
            BankAccountName = Normalize(BankAccountName)
        };
    }

    private static string? Normalize(string? value)
    {
        return string.IsNullOrWhiteSpace(value)
            ? null
            : value.Trim();
    }
}

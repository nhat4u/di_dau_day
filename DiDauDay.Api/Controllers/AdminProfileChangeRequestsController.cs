using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
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
    private readonly DiDauDayDbContext _db;

    public AdminProfileChangeRequestsController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    // QTV xem danh sách yêu cầu
    [HttpGet]
    public async Task<IActionResult> GetRequests(
        [FromQuery] string? status
    )
    {
        var query = _db.ProfileChangeRequests
            .AsNoTracking()
            .AsQueryable();

        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status
                .Trim()
                .ToLowerInvariant();

            query = query.Where(r =>
                r.Status == normalizedStatus
            );
        }

        var requests = await query
            .OrderByDescending(r => r.CreatedAt)
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

        return Ok(new
        {
            success = true,
            total = requests.Count,
            requests
        });
    }

    // QTV duyệt yêu cầu
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

        var changeRequest =
            await _db.ProfileChangeRequests
                .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu sửa hồ sơ."
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

        changeRequest.Status = "approved";
        changeRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã duyệt yêu cầu sửa hồ sơ."
                : request.AdminNote.Trim();

        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = DateTime.Now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message =
                "Đã duyệt yêu cầu sửa hồ sơ.",
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

    // QTV cập nhật hồ sơ và hoàn tất yêu cầu
    [HttpPatch("{id}/complete")]
    public async Task<IActionResult> CompleteRequest(
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

        var changeRequest =
            await _db.ProfileChangeRequests
                .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu sửa hồ sơ."
            });
        }

        if (changeRequest.Status != "approved")
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể hoàn tất yêu cầu đã được duyệt.",
                currentStatus = changeRequest.Status
            });
        }

        var profile = await _db.OwnerProfiles
            .FirstOrDefaultAsync(p =>
                p.UserId == changeRequest.OwnerId
            );

        if (profile is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy hồ sơ chủ homestay."
            });
        }

        var hasAnyChange =
            !string.IsNullOrWhiteSpace(request.CitizenId) ||
            !string.IsNullOrWhiteSpace(request.Address) ||
            !string.IsNullOrWhiteSpace(request.BankName) ||
            !string.IsNullOrWhiteSpace(request.BankAccount) ||
            !string.IsNullOrWhiteSpace(
                request.BankAccountName
            );

        if (!hasAnyChange)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "QTV phải nhập ít nhất một thông tin cần thay đổi."
            });
        }

        if (!string.IsNullOrWhiteSpace(request.CitizenId))
        {
            var citizenId = request.CitizenId.Trim();

            var citizenIdExists =
                await _db.OwnerProfiles.AnyAsync(p =>
                    p.UserId != changeRequest.OwnerId &&
                    p.CitizenId == citizenId
                );

            if (citizenIdExists)
            {
                return Conflict(new
                {
                    success = false,
                    message =
                        "Số căn cước công dân đã được sử dụng."
                });
            }

            profile.CitizenId = citizenId;
        }

        if (!string.IsNullOrWhiteSpace(request.Address))
        {
            profile.Address = request.Address.Trim();
        }

        if (!string.IsNullOrWhiteSpace(request.BankName))
        {
            profile.BankName = request.BankName.Trim();
        }

        if (!string.IsNullOrWhiteSpace(
                request.BankAccount
            ))
        {
            profile.BankAccount =
                request.BankAccount.Trim();
        }

        if (!string.IsNullOrWhiteSpace(
                request.BankAccountName
            ))
        {
            profile.BankAccountName =
                request.BankAccountName.Trim();
        }

        var now = DateTime.Now;

        changeRequest.Status = "completed";
        changeRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã cập nhật hồ sơ thành công."
                : request.AdminNote.Trim();

        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message =
                "Đã cập nhật hồ sơ chủ homestay.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.Status,
                changeRequest.AdminNote,
                changeRequest.ProcessedAt
            },
            profile = new
            {
                profile.Id,
                profile.UserId,
                profile.CitizenId,
                profile.Address,
                profile.BankName,
                profile.BankAccount,
                profile.BankAccountName
            }
        });
    }

    // QTV từ chối yêu cầu
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

        var changeRequest =
            await _db.ProfileChangeRequests
                .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu sửa hồ sơ."
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
                message =
                    "Yêu cầu này không thể bị từ chối.",
                currentStatus = changeRequest.Status
            });
        }

        changeRequest.Status = "rejected";
        changeRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã từ chối yêu cầu sửa hồ sơ."
                : request.AdminNote.Trim();

        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = DateTime.Now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message =
                "Đã từ chối yêu cầu sửa hồ sơ.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.Status,
                changeRequest.AdminNote,
                changeRequest.ProcessedAt
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

public sealed class ReviewProfileChangeRequestDto
{
    [StringLength(
        255,
        ErrorMessage =
            "Ghi chú không được vượt quá 255 ký tự."
    )]
    public string? AdminNote { get; set; }
}

public sealed class ApplyProfileChangesDto
{
    [RegularExpression(
        @"^\d{9,20}$",
        ErrorMessage =
            "Số căn cước phải gồm từ 9 đến 20 chữ số."
    )]
    public string? CitizenId { get; set; }

    [StringLength(
        255,
        MinimumLength = 10,
        ErrorMessage =
            "Địa chỉ phải có từ 10 đến 255 ký tự."
    )]
    public string? Address { get; set; }

    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage =
            "Tên ngân hàng phải có từ 2 đến 100 ký tự."
    )]
    public string? BankName { get; set; }

    [RegularExpression(
        @"^\d{6,30}$",
        ErrorMessage =
            "Số tài khoản phải gồm từ 6 đến 30 chữ số."
    )]
    public string? BankAccount { get; set; }

    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage =
            "Tên chủ tài khoản phải có từ 2 đến 100 ký tự."
    )]
    public string? BankAccountName { get; set; }

    [StringLength(
        255,
        ErrorMessage =
            "Ghi chú không được vượt quá 255 ký tự."
    )]
    public string? AdminNote { get; set; }
}
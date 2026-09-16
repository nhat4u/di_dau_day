using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/owner/profile-change-requests")]
[Authorize(Roles = "owner")]
public sealed class OwnerProfileChangeRequestsController
    : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public OwnerProfileChangeRequestsController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    // Chủ homestay gửi yêu cầu sửa hồ sơ
    [HttpPost]
    public async Task<IActionResult> CreateRequest(
        [FromBody] CreateProfileChangeRequestDto request
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

        var hasProfile = await _db.OwnerProfiles
            .AnyAsync(p => p.UserId == ownerId);

        if (!hasProfile)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Bạn chưa có hồ sơ chủ homestay để yêu cầu chỉnh sửa."
            });
        }

        var hasActiveRequest =
            await _db.ProfileChangeRequests.AnyAsync(r =>
                r.OwnerId == ownerId &&
                (
                    r.Status == "pending" ||
                    r.Status == "approved"
                )
            );

        if (hasActiveRequest)
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Bạn đang có một yêu cầu sửa hồ sơ chờ QTV xử lý."
            });
        }

        var changeRequest = new ProfileChangeRequest
        {
            OwnerId = ownerId,
            Reason = request.Reason.Trim(),
            RequestedInformation =
                request.RequestedInformation.Trim(),
            Status = "pending",
            AdminNote = null,
            ProcessedBy = null,
            CreatedAt = DateTime.Now,
            ProcessedAt = null
        };

        _db.ProfileChangeRequests.Add(changeRequest);
        await _db.SaveChangesAsync();

        return StatusCode(201, new
        {
            success = true,
            message =
                "Đã gửi yêu cầu sửa hồ sơ. Vui lòng chờ QTV xử lý.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.OwnerId,
                changeRequest.Reason,
                changeRequest.RequestedInformation,
                changeRequest.Status,
                changeRequest.CreatedAt
            }
        });
    }

    // Chủ homestay xem các yêu cầu của mình
    [HttpGet]
    public async Task<IActionResult> GetMyRequests()
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var requests = await _db.ProfileChangeRequests
            .AsNoTracking()
            .Where(r => r.OwnerId == ownerId)
            .OrderByDescending(r => r.CreatedAt)
            .Select(r => new
            {
                r.Id,
                r.Reason,
                r.RequestedInformation,
                r.Status,
                r.AdminNote,
                r.CreatedAt,
                r.ProcessedAt,
                processedByName =
                    r.ProcessedByNavigation != null
                        ? r.ProcessedByNavigation.FullName
                        : null
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = requests.Count,
            requests
        });
    }

    // Chủ homestay xem chi tiết một yêu cầu
    [HttpGet("{id}")]
    public async Task<IActionResult> GetMyRequest(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var changeRequest = await _db.ProfileChangeRequests
            .AsNoTracking()
            .Where(r =>
                r.Id == id &&
                r.OwnerId == ownerId
            )
            .Select(r => new
            {
                r.Id,
                r.Reason,
                r.RequestedInformation,
                r.Status,
                r.AdminNote,
                r.CreatedAt,
                r.ProcessedAt,
                processedByName =
                    r.ProcessedByNavigation != null
                        ? r.ProcessedByNavigation.FullName
                        : null
            })
            .FirstOrDefaultAsync();

        if (changeRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu sửa hồ sơ."
            });
        }

        return Ok(new
        {
            success = true,
            changeRequest
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

public sealed class CreateProfileChangeRequestDto
{
    [Required(ErrorMessage = "Vui lòng nhập lý do.")]
    [StringLength(
        500,
        MinimumLength = 5,
        ErrorMessage = "Lý do phải có từ 5 đến 500 ký tự."
    )]
    public string Reason { get; set; } = string.Empty;

    [Required(
        ErrorMessage =
            "Vui lòng nhập thông tin muốn thay đổi."
    )]
    [StringLength(
        2000,
        MinimumLength = 5,
        ErrorMessage =
            "Thông tin yêu cầu phải có từ 5 đến 2000 ký tự."
    )]
    public string RequestedInformation { get; set; }
        = string.Empty;
}
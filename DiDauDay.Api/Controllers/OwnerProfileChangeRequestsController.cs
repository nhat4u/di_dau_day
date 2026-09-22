using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using System.Text.Json;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Authorize(Roles = "owner")]
[Route("api/owner/homestay-change-requests")]
public sealed class OwnerHomestayChangeRequestsController : ControllerBase
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase
    };

    private readonly DiDauDayDbContext _db;

    public OwnerHomestayChangeRequestsController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    [HttpGet]
    public async Task<IActionResult> GetMine()
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var rows = await _db.HomestayChangeRequests
            .AsNoTracking()
            .Where(r => r.OwnerId == ownerId)
            .OrderByDescending(r => r.CreatedAt)
            .Select(r => new
            {
                r.Id,
                r.HomestayId,
                r.RequestType,
                r.RequestedData,
                r.Reason,
                r.Status,
                r.AdminNote,
                r.CreatedAt,
                r.ProcessedAt,
                HomestayName = r.Homestay.Name,
                HomestaySlug = r.Homestay.Slug,
                ProcessedByName = r.ProcessedByNavigation == null
                    ? null
                    : r.ProcessedByNavigation.FullName
            })
            .ToListAsync();

        var requests = rows.Select(r => new
        {
            r.Id,
            r.HomestayId,
            r.HomestayName,
            r.HomestaySlug,
            r.RequestType,
            Proposed = DeserializePayload(r.RequestedData),
            r.Reason,
            r.Status,
            r.AdminNote,
            r.CreatedAt,
            r.ProcessedAt,
            r.ProcessedByName
        });

        return Ok(new
        {
            success = true,
            total = rows.Count,
            requests
        });
    }

    [HttpPost]
    public async Task<IActionResult> Create(
        [FromBody] CreateHomestayChangeRequestDto request
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

        var homestay = await _db.Homestays
            .Include(h => h.HomestayPrice)
            .FirstOrDefaultAsync(h =>
                h.Id == request.HomestayId &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        if (homestay == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        var requestType = request.RequestType
            .Trim()
            .ToLowerInvariant();

        var hasPendingRequest = await _db.HomestayChangeRequests
            .AnyAsync(r =>
                r.HomestayId == homestay.Id &&
                r.Status == "pending"
            );

        if (hasPendingRequest)
        {
            return Conflict(new
            {
                success = false,
                message = "Homestay này đang có một yêu cầu chờ QTV xử lý."
            });
        }

        string? requestedData = null;

        if (requestType == "update")
        {
            if (homestay.Status != "approved" && homestay.Status != "maintenance")
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Chỉ homestay đã đăng mới cần gửi yêu cầu chỉnh sửa."
                });
            }

            if (request.ProposedHomestay == null || request.ProposedPrices == null)
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Vui lòng nhập đầy đủ thông tin và bảng giá muốn thay đổi."
                });
            }

            if (request.ProposedPrices.PriceCombo4Hours <
                request.ProposedPrices.PriceFirst2Hours)
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Giá combo 4 giờ không được thấp hơn giá 2 giờ đầu."
                });
            }

            requestedData = JsonSerializer.Serialize(
                new HomestayUpdatePayload
                {
                    Homestay = request.ProposedHomestay,
                    Prices = request.ProposedPrices
                },
                JsonOptions
            );
        }
        else if (requestType is "maintenance" or "close")
        {
            if (homestay.Status != "approved")
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Chỉ homestay đang hoạt động mới có thể yêu cầu tạm ngừng hoặc đóng."
                });
            }
        }
        else if (requestType == "reactivate")
        {
            if (homestay.Status != "maintenance")
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Chỉ homestay đang tạm ngừng mới có thể yêu cầu hoạt động lại."
                });
            }
        }
        else
        {
            return BadRequest(new
            {
                success = false,
                message = "Loại yêu cầu không hợp lệ."
            });
        }

        var changeRequest = new HomestayChangeRequest
        {
            HomestayId = homestay.Id,
            OwnerId = ownerId,
            RequestType = requestType,
            RequestedData = requestedData,
            Reason = request.Reason.Trim(),
            Status = "pending",
            AdminNote = null,
            ProcessedBy = null,
            CreatedAt = DateTime.Now,
            ProcessedAt = null
        };

        _db.HomestayChangeRequests.Add(changeRequest);
        await _db.SaveChangesAsync();

        return StatusCode(StatusCodes.Status201Created, new
        {
            success = true,
            message = "Đã gửi yêu cầu. Vui lòng chờ QTV xử lý.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.HomestayId,
                changeRequest.RequestType,
                changeRequest.Reason,
                changeRequest.Status,
                changeRequest.CreatedAt
            }
        });
    }

    [HttpDelete("{id}")]
    public async Task<IActionResult> Cancel(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "Token đăng nhập không hợp lệ."
            });
        }

        var changeRequest = await _db.HomestayChangeRequests
            .FirstOrDefaultAsync(r =>
                r.Id == id &&
                r.OwnerId == ownerId
            );

        if (changeRequest == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu."
            });
        }

        if (changeRequest.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được QTV xử lý."
            });
        }

        _db.HomestayChangeRequests.Remove(changeRequest);
        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã hủy yêu cầu thay đổi."
        });
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(ClaimTypes.NameIdentifier);
        return uint.TryParse(userIdValue, out userId);
    }

    private static HomestayUpdatePayload? DeserializePayload(string? value)
    {
        if (string.IsNullOrWhiteSpace(value))
        {
            return null;
        }

        try
        {
            var payload = JsonSerializer.Deserialize<HomestayUpdatePayload>(
                value,
                JsonOptions
            );

            if (payload != null && payload.Homestay.Amenities == null)
            {
                payload.Homestay.Amenities =
                    HomestayAmenityCatalog.ResolveRequested(
                        null,
                        payload.Homestay.HasBathtub,
                        payload.Homestay.HasBalcony,
                        payload.Homestay.HasMiniPool
                    );
            }

            return payload;
        }
        catch (JsonException)
        {
            return null;
        }
    }
}

public sealed class CreateHomestayChangeRequestDto
{
    [Range(
        typeof(uint),
        "1",
        "4294967295",
        ErrorMessage = "Homestay không hợp lệ."
    )]
    public uint HomestayId { get; set; }

    [Required(ErrorMessage = "Vui lòng chọn loại yêu cầu.")]
    [RegularExpression(
        "^(update|maintenance|reactivate|close)$",
        ErrorMessage = "Loại yêu cầu không hợp lệ."
    )]
    public string RequestType { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập lý do.")]
    [StringLength(
        1000,
        MinimumLength = 5,
        ErrorMessage = "Lý do phải có từ 5 đến 1000 ký tự."
    )]
    public string Reason { get; set; } = string.Empty;

    public SaveHomestayRequest? ProposedHomestay { get; set; }

    public SaveHomestayPriceRequest? ProposedPrices { get; set; }
}

public sealed class HomestayUpdatePayload
{
    public SaveHomestayRequest Homestay { get; set; } = new();

    public SaveHomestayPriceRequest Prices { get; set; } = new();
}

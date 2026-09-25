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
[Authorize(Roles = "admin")]
[Route("api/admin/homestay-change-requests")]
public sealed class AdminHomestayChangeRequestsController : ControllerBase
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.CamelCase
    };

    private readonly DiDauDayDbContext _db;

    public AdminHomestayChangeRequestsController(
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
        var query = _db.HomestayChangeRequests
            .AsNoTracking()
            .AsQueryable();

        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status.Trim().ToLowerInvariant();
            if (normalizedStatus is not ("pending" or "approved" or "rejected"))
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Trạng thái lọc không hợp lệ."
                });
            }

            query = query.Where(r => r.Status == normalizedStatus);
        }

        var now = DateTime.Now;
        var rows = await query
            .OrderByDescending(r => r.CreatedAt)
            .Select(r => new
            {
                r.Id,
                r.HomestayId,
                r.OwnerId,
                r.RequestType,
                r.RequestedData,
                r.Reason,
                r.Status,
                r.AdminNote,
                r.CreatedAt,
                r.ProcessedAt,
                ProcessedByName = r.ProcessedByNavigation == null
                    ? null
                    : r.ProcessedByNavigation.FullName,
                Owner = new
                {
                    r.Owner.Id,
                    r.Owner.FullName,
                    r.Owner.Email,
                    r.Owner.Phone
                },
                Homestay = new
                {
                    r.Homestay.Id,
                    r.Homestay.Name,
                    r.Homestay.Slug,
                    r.Homestay.RoomRank,
                    r.Homestay.Description,
                    r.Homestay.Address,
                    r.Homestay.Province,
                    r.Homestay.TouristDestination,
                    r.Homestay.MaxGuests,
                    r.Homestay.HasBathtub,
                    r.Homestay.HasBalcony,
                    r.Homestay.HasMiniPool,
                    r.Homestay.AmenitiesJson,
                    r.Homestay.Status,
                    Prices = r.Homestay.HomestayPrice == null
                        ? null
                        : new
                        {
                            r.Homestay.HomestayPrice.PriceFirst2Hours,
                            r.Homestay.HomestayPrice.PriceCombo4Hours,
                            r.Homestay.HomestayPrice.PriceExtraHour,
                            r.Homestay.HomestayPrice.PriceOvernightWeekday,
                            r.Homestay.HomestayPrice.PriceOvernightWeekend,
                            r.Homestay.HomestayPrice.PriceDayNightWeekday,
                            r.Homestay.HomestayPrice.PriceDayNightWeekend,
                            r.Homestay.HomestayPrice.PriceDayWeekday,
                            r.Homestay.HomestayPrice.PriceDayWeekend
                        }
                },
                FutureBookingCount = r.Homestay.Bookings.Count(b =>
                    b.CheckOut > now &&
                    b.Status != "cancelled" &&
                    b.Status != "refunded" &&
                    b.Status != "expired"
                )
            })
            .ToListAsync();

        var requests = rows.Select(r => new
        {
            r.Id,
            r.HomestayId,
            r.OwnerId,
            r.RequestType,
            Proposed = DeserializePayload(r.RequestedData),
            r.Reason,
            r.Status,
            r.AdminNote,
            r.CreatedAt,
            r.ProcessedAt,
            r.ProcessedByName,
            r.Owner,
            Homestay = new
            {
                r.Homestay.Id,
                r.Homestay.Name,
                r.Homestay.Slug,
                r.Homestay.RoomRank,
                r.Homestay.Description,
                r.Homestay.Address,
                r.Homestay.Province,
                r.Homestay.TouristDestination,
                r.Homestay.MaxGuests,
                r.Homestay.HasBathtub,
                r.Homestay.HasBalcony,
                r.Homestay.HasMiniPool,
                Amenities = HomestayAmenityCatalog.ResolveStored(
                    r.Homestay.AmenitiesJson,
                    r.Homestay.HasBathtub,
                    r.Homestay.HasBalcony,
                    r.Homestay.HasMiniPool
                ),
                r.Homestay.Status,
                r.Homestay.Prices
            },
            r.FutureBookingCount
        });

        return Ok(new
        {
            success = true,
            total = rows.Count,
            requests
        });
    }

    [HttpPatch("{id}/approve")]
    public async Task<IActionResult> Approve(
        uint id,
        [FromBody] ReviewHomestayChangeRequestDto request
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

        var changeRequest = await _db.HomestayChangeRequests
            .Include(r => r.Homestay)
                .ThenInclude(h => h.HomestayPrice)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu thay đổi homestay."
            });
        }

        if (changeRequest.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được xử lý."
            });
        }

        await using var transaction = await _db.Database.BeginTransactionAsync();

        if (changeRequest.RequestType == "update")
        {
            var payload = DeserializePayload(changeRequest.RequestedData);
            if (payload == null)
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Dữ liệu chỉnh sửa không hợp lệ."
                });
            }

            OwnerHomestaysController.ApplyBasicInformation(
                changeRequest.Homestay,
                payload.Homestay
            );
            changeRequest.Homestay.Slug = await GenerateUniqueSlugAsync(
                payload.Homestay.Name,
                changeRequest.Homestay.Id
            );

            var prices = changeRequest.Homestay.HomestayPrice;
            if (prices == null)
            {
                prices = new HomestayPrice
                {
                    HomestayId = changeRequest.Homestay.Id,
                    CreatedAt = DateTime.Now
                };
                _db.HomestayPrices.Add(prices);
                changeRequest.Homestay.HomestayPrice = prices;
            }

            ApplyPrices(prices, payload.Prices);
            OwnerHomestaysController.SyncSummaryPrices(
                changeRequest.Homestay,
                prices
            );
        }
        else if (changeRequest.RequestType == "maintenance")
        {
            changeRequest.Homestay.Status = "maintenance";
        }
        else if (changeRequest.RequestType == "reactivate")
        {
            changeRequest.Homestay.Status = "approved";
        }
        else if (changeRequest.RequestType == "close")
        {
            changeRequest.Homestay.Status = "maintenance";
            changeRequest.Homestay.IsDeleted = true;
        }
        else
        {
            return BadRequest(new
            {
                success = false,
                message = "Loại yêu cầu không hợp lệ."
            });
        }

        var now = DateTime.Now;
        changeRequest.Homestay.UpdatedAt = now;
        changeRequest.Status = "approved";
        changeRequest.AdminNote = string.IsNullOrWhiteSpace(request.AdminNote)
            ? "QTV đã duyệt yêu cầu."
            : request.AdminNote.Trim();
        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = now;

        await _db.SaveChangesAsync();
        await transaction.CommitAsync();

        return Ok(new
        {
            success = true,
            message = "Đã duyệt và áp dụng yêu cầu thay đổi homestay.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.HomestayId,
                changeRequest.RequestType,
                changeRequest.Status,
                changeRequest.AdminNote,
                changeRequest.ProcessedAt
            }
        });
    }

    [HttpPatch("{id}/reject")]
    public async Task<IActionResult> Reject(
        uint id,
        [FromBody] RejectHomestayChangeRequestDto request
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

        var changeRequest = await _db.HomestayChangeRequests
            .Include(r => r.Homestay)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (changeRequest == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy yêu cầu thay đổi homestay."
            });
        }

        if (changeRequest.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được xử lý."
            });
        }

        changeRequest.Status = "rejected";
        changeRequest.AdminNote = request.AdminNote.Trim();
        changeRequest.ProcessedBy = adminId;
        changeRequest.ProcessedAt = DateTime.Now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã từ chối yêu cầu thay đổi homestay.",
            changeRequest = new
            {
                changeRequest.Id,
                changeRequest.Status,
                changeRequest.AdminNote,
                changeRequest.ProcessedAt
            }
        });
    }

    private static void ApplyPrices(
        HomestayPrice target,
        SaveHomestayPriceRequest source
    )
    {
        target.PriceFirst2Hours = source.PriceFirst2Hours;
        target.PriceCombo4Hours = source.PriceCombo4Hours;
        target.PriceExtraHour = source.PriceExtraHour;
        target.PriceOvernightWeekday = source.PriceOvernightWeekday;
        target.PriceOvernightWeekend = source.PriceOvernightWeekend;
        target.PriceDayNightWeekday = source.PriceDayNightWeekday;
        target.PriceDayNightWeekend = source.PriceDayNightWeekend;
        target.PriceDayWeekday = source.PriceDayWeekday;
        target.PriceDayWeekend = source.PriceDayWeekend;
        target.UpdatedAt = DateTime.Now;
    }

    private async Task<string> GenerateUniqueSlugAsync(
        string name,
        uint ignoredId
    )
    {
        var baseSlug = OwnerHomestaysController.CreateSlug(name);
        var slug = baseSlug;
        var number = 2;

        while (await _db.Homestays.AnyAsync(h =>
            h.Slug == slug && h.Id != ignoredId
        ))
        {
            slug = $"{baseSlug}-{number}";
            number++;
        }

        return slug;
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

public sealed class ReviewHomestayChangeRequestDto
{
    [StringLength(
        1000,
        ErrorMessage = "Ghi chú QTV không được vượt quá 1000 ký tự."
    )]
    public string? AdminNote { get; set; }
}

public sealed class RejectHomestayChangeRequestDto
{
    [Required(ErrorMessage = "Vui lòng nhập lý do từ chối.")]
    [StringLength(
        1000,
        MinimumLength = 5,
        ErrorMessage = "Lý do từ chối phải có từ 5 đến 1000 ký tự."
    )]
    public string AdminNote { get; set; } = string.Empty;
}

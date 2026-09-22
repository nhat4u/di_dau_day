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
[Route("api/owner/homestays/{homestayId}/prices")]
public class OwnerHomestayPricesController : ControllerBase
{
    private readonly DiDauDayDbContext _context;

    public OwnerHomestayPricesController(
        DiDauDayDbContext context
    )
    {
        _context = context;
    }

    // Xem bảng giá chi tiết
    [HttpGet]
    public async Task<IActionResult> GetPrices(uint homestayId)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var ownsHomestay = await _context.Homestays
            .AsNoTracking()
            .AnyAsync(h =>
                h.Id == homestayId &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        if (!ownsHomestay)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        var prices = await _context.HomestayPrices
            .AsNoTracking()
            .Where(p => p.HomestayId == homestayId)
            .Select(p => new
            {
                p.Id,
                p.HomestayId,
                p.PriceFirst2Hours,
                p.PriceCombo4Hours,
                p.PriceExtraHour,
                p.PriceOvernightWeekday,
                p.PriceOvernightWeekend,
                p.PriceDayNightWeekday,
                p.PriceDayNightWeekend,
                p.PriceDayWeekday,
                p.PriceDayWeekend,
                p.CreatedAt,
                p.UpdatedAt
            })
            .FirstOrDefaultAsync();

        if (prices == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Homestay chưa có bảng giá chi tiết."
            });
        }

        return Ok(new
        {
            success = true,
            prices
        });
    }

    // Tạo mới hoặc cập nhật bảng giá
    [HttpPut]
    public async Task<IActionResult> SavePrices(
        uint homestayId,
        [FromBody] SaveHomestayPriceRequest request
    )
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var homestay = await _context.Homestays
            .FirstOrDefaultAsync(h =>
                h.Id == homestayId &&
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

        if (homestay.Status != "draft")
        {
            return Conflict(new
            {
                success = false,
                message =
                    "Homestay đã đăng. Vui lòng gửi yêu cầu thay đổi bảng giá để QTV duyệt."
            });
        }

        if (request.PriceCombo4Hours <
            request.PriceFirst2Hours)
        {
            return BadRequest(new
            {
                success = false,
                message = "Giá combo 4 giờ không được thấp hơn giá 2 giờ đầu."
            });
        }

        var prices = await _context.HomestayPrices
            .FirstOrDefaultAsync(p =>
                p.HomestayId == homestayId
            );

        var isNew = prices == null;
        var now = DateTime.Now;

        if (prices == null)
        {
            prices = new HomestayPrice
            {
                HomestayId = homestayId,
                CreatedAt = now
            };

            _context.HomestayPrices.Add(prices);
        }

        prices.PriceFirst2Hours =
            request.PriceFirst2Hours;
        prices.PriceCombo4Hours =
            request.PriceCombo4Hours;
        prices.PriceExtraHour =
            request.PriceExtraHour;
        prices.PriceOvernightWeekday =
            request.PriceOvernightWeekday;
        prices.PriceOvernightWeekend =
            request.PriceOvernightWeekend;
        prices.PriceDayNightWeekday =
            request.PriceDayNightWeekday;
        prices.PriceDayNightWeekend =
            request.PriceDayNightWeekend;
        prices.PriceDayWeekday =
            request.PriceDayWeekday;
        prices.PriceDayWeekend =
            request.PriceDayWeekend;
        prices.UpdatedAt = now;

        OwnerHomestaysController.SyncSummaryPrices(
            homestay,
            prices
        );
        homestay.UpdatedAt = now;

        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = isNew
                ? "Tạo bảng giá thành công."
                : "Cập nhật bảng giá thành công.",
            prices = new
            {
                prices.Id,
                prices.HomestayId,
                prices.PriceFirst2Hours,
                prices.PriceCombo4Hours,
                prices.PriceExtraHour,
                prices.PriceOvernightWeekday,
                prices.PriceOvernightWeekend,
                prices.PriceDayNightWeekday,
                prices.PriceDayNightWeekend,
                prices.PriceDayWeekday,
                prices.PriceDayWeekend,
                prices.CreatedAt,
                prices.UpdatedAt
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

public sealed class SaveHomestayPriceRequest
{
    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá 2 giờ đầu phải lớn hơn 0."
    )]
    public decimal PriceFirst2Hours { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá combo 4 giờ phải lớn hơn 0."
    )]
    public decimal PriceCombo4Hours { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá giờ phát sinh phải lớn hơn 0."
    )]
    public decimal PriceExtraHour { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá qua đêm ngày thường phải lớn hơn 0."
    )]
    public decimal PriceOvernightWeekday { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá qua đêm cuối tuần phải lớn hơn 0."
    )]
    public decimal PriceOvernightWeekend { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá ngày đêm ngày thường phải lớn hơn 0."
    )]
    public decimal PriceDayNightWeekday { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá ngày đêm cuối tuần phải lớn hơn 0."
    )]
    public decimal PriceDayNightWeekend { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá thuê ngày thường phải lớn hơn 0."
    )]
    public decimal PriceDayWeekday { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá thuê ngày cuối tuần phải lớn hơn 0."
    )]
    public decimal PriceDayWeekend { get; set; }
}

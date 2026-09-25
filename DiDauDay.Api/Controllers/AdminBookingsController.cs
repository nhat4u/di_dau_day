using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/admin/bookings")]
[Authorize(Roles = "admin")]
public sealed class AdminBookingsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public AdminBookingsController(
        DiDauDayDbContext db
    )
    {
        _db = db;
    }

    // QTV xem tất cả đơn đặt phòng
    [HttpGet]
    public async Task<IActionResult> GetBookings(
        [FromQuery] string? status,
        [FromQuery] string? search,
        [FromQuery] int page = 1,
        [FromQuery] int pageSize = 20
    )
    {
        page = Math.Max(page, 1);
        pageSize = Math.Clamp(pageSize, 1, 100);

        IQueryable<Booking> query =
            _db.Bookings.AsNoTracking();

        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status
                .Trim()
                .ToLowerInvariant();

            query = query.Where(b =>
                b.Status == normalizedStatus
            );
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            var keyword = search.Trim();

            query = query.Where(b =>
                b.BookingCode.Contains(keyword) ||
                b.Guest.FullName.Contains(keyword) ||
                b.Guest.Email.Contains(keyword) ||
                b.Homestay.Name.Contains(keyword)
            );
        }

        var total = await query.CountAsync();

        var bookings = await query
            .OrderByDescending(b => b.CreatedAt)
            .Skip((page - 1) * pageSize)
            .Take(pageSize)
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.BookingType,
                b.CheckIn,
                b.CheckOut,
                b.GuestCount,
                b.TotalAmount,
                b.Status,
                b.CreatedAt,
                b.UpdatedAt,
                paymentStatus = b.Payment != null
                    ? b.Payment.Status
                    : null,
                latestRefundStatus = b.RefundRequests
                    .OrderByDescending(r => r.CreatedAt)
                    .Select(r => r.Status)
                    .FirstOrDefault(),
                homestay = new
                {
                    b.Homestay.Id,
                    b.Homestay.Name,
                    b.Homestay.Slug,
                    ownerId = b.Homestay.Owner.Id,
                    ownerName = b.Homestay.Owner.FullName
                },
                guest = new
                {
                    b.Guest.Id,
                    b.Guest.FullName,
                    b.Guest.Email,
                    b.Guest.Phone
                }
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total,
            page,
            pageSize,
            totalPages = (int)Math.Ceiling(
                total / (double)pageSize
            ),
            bookings
        });
    }

    // QTV xem chi tiết một đơn
    [HttpGet("{id}")]
    public async Task<IActionResult> GetBooking(uint id)
    {
        var booking = await _db.Bookings
            .AsNoTracking()
            .Where(b => b.Id == id)
            .Select(b => new
            {
                b.Id,
                b.BookingCode,
                b.BookingType,
                b.CheckIn,
                b.CheckOut,
                b.GuestCount,
                b.TotalAmount,
                b.Status,
                b.CreatedAt,
                b.UpdatedAt,
                homestay = new
                {
                    b.Homestay.Id,
                    b.Homestay.Name,
                    b.Homestay.Slug,
                    b.Homestay.Address,
                    b.Homestay.Province,
                    owner = new
                    {
                        b.Homestay.Owner.Id,
                        b.Homestay.Owner.FullName,
                        b.Homestay.Owner.Email,
                        b.Homestay.Owner.Phone
                    }
                },
                guest = new
                {
                    b.Guest.Id,
                    b.Guest.FullName,
                    b.Guest.Email,
                    b.Guest.Phone
                },
                payment = b.Payment == null
                    ? null
                    : new
                    {
                        b.Payment.Id,
                        b.Payment.TransactionCode,
                        b.Payment.PaymentMethod,
                        b.Payment.Amount,
                        b.Payment.Status,
                        b.Payment.PaidAt,
                        b.Payment.CreatedAt
                    },
                settlement = b.Settlement == null
                    ? null
                    : new
                    {
                        b.Settlement.Id,
                        b.Settlement.GrossAmount,
                        b.Settlement.PlatformFee,
                        b.Settlement.OwnerAmount,
                        b.Settlement.Status,
                        b.Settlement.SettledAt,
                        b.Settlement.CreatedAt
                    },
                refundRequests = b.RefundRequests
                    .OrderByDescending(r => r.CreatedAt)
                    .Select(r => new
                    {
                        r.Id,
                        r.Reason,
                        r.Description,
                        r.RefundAmount,
                        r.Status,
                        r.AdminNote,
                        r.CreatedAt,
                        r.ResolvedAt
                    })
                    .ToList()
            })
            .FirstOrDefaultAsync();

        if (booking is null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy đơn đặt phòng."
            });
        }

        return Ok(new
        {
            success = true,
            booking
        });
    }
}

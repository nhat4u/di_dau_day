using System.ComponentModel.DataAnnotations;
using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/admin/refunds")]
[Authorize(Roles = "admin")]
public sealed class AdminRefundsController : ControllerBase
{
    private readonly DiDauDayDbContext _db;

    public AdminRefundsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // QTV xem danh sách yêu cầu hoàn tiền
    [HttpGet]
    public async Task<IActionResult> GetRefundRequests(
        [FromQuery] string? status
    )
    {
        IQueryable<RefundRequest> query =
            _db.RefundRequests.AsNoTracking();

        if (!string.IsNullOrWhiteSpace(status))
        {
            var normalizedStatus = status
                .Trim()
                .ToLowerInvariant();

            query = query.Where(r =>
                r.Status == normalizedStatus
            );
        }

        var refundRequests = await query
            .OrderByDescending(r => r.CreatedAt)
            .Select(r => new
            {
                r.Id,
                r.BookingId,
                r.Booking.BookingCode,
                homestayName = r.Booking.Homestay.Name,
                r.Reason,
                r.Description,
                r.RefundAmount,
                r.Status,
                r.AdminNote,
                r.CreatedAt,
                r.ResolvedAt,
                r.ResolvedBy,
                resolvedByName =
                    r.ResolvedByNavigation != null
                        ? r.ResolvedByNavigation.FullName
                        : null,
                requestedBy = new
                {
                    r.RequestedByNavigation.Id,
                    r.RequestedByNavigation.FullName,
                    r.RequestedByNavigation.Email,
                    r.RequestedByNavigation.Phone
                },
                bookingStatus = r.Booking.Status,
                paymentStatus = r.Booking.Payment != null
                    ? r.Booking.Payment.Status
                    : null
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = refundRequests.Count,
            refundRequests
        });
    }

    // QTV duyệt yêu cầu hoàn tiền
    [HttpPatch("{id}/approve")]
    public async Task<IActionResult> ApproveRefund(
        uint id,
        [FromBody] ReviewRefundRequest request
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

        var refundRequest = await _db.RefundRequests
            .Include(r => r.Booking)
            .ThenInclude(b => b.Payment)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (refundRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu hoàn tiền."
            });
        }

        if (refundRequest.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được xử lý.",
                currentStatus = refundRequest.Status
            });
        }

        if (
            refundRequest.Booking.Payment is null ||
            refundRequest.Booking.Payment.Status != "held"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Khoản tiền không còn ở trạng thái tạm giữ."
            });
        }

        refundRequest.Status = "approved";
        refundRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã duyệt yêu cầu hoàn tiền."
                : request.AdminNote.Trim();

        refundRequest.ResolvedBy = adminId;
        refundRequest.ResolvedAt = DateTime.Now;

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message =
                "Đã duyệt yêu cầu. QTV có thể tiến hành hoàn tiền.",
            refundRequest = new
            {
                refundRequest.Id,
                refundRequest.BookingId,
                refundRequest.RefundAmount,
                refundRequest.Status,
                refundRequest.AdminNote,
                refundRequest.ResolvedAt
            }
        });
    }

    // QTV xác nhận đã hoàn tiền
    [HttpPatch("{id}/complete")]
    public async Task<IActionResult> CompleteRefund(
        uint id,
        [FromBody] ReviewRefundRequest request
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

        var refundRequest = await _db.RefundRequests
            .Include(r => r.Booking)
            .ThenInclude(b => b.Payment)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (refundRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu hoàn tiền."
            });
        }

        if (refundRequest.Status != "approved")
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Chỉ có thể hoàn tất yêu cầu đã được duyệt.",
                currentStatus = refundRequest.Status
            });
        }

        if (
            refundRequest.Booking.Payment is null ||
            refundRequest.Booking.Payment.Status != "held"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Khoản tiền không còn ở trạng thái tạm giữ."
            });
        }

        var now = DateTime.Now;

        refundRequest.Status = "completed";
        refundRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã hoàn tiền thành công."
                : request.AdminNote.Trim();

        refundRequest.ResolvedBy = adminId;
        refundRequest.ResolvedAt = now;

        refundRequest.Booking.Status = "refunded";
        refundRequest.Booking.UpdatedAt = now;

        refundRequest.Booking.Payment.Status = "refunded";

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã hoàn tiền cho khách thành công.",
            refundRequest = new
            {
                refundRequest.Id,
                refundRequest.BookingId,
                refundRequest.RefundAmount,
                refundRequest.Status,
                refundRequest.AdminNote,
                refundRequest.ResolvedAt
            },
            bookingStatus = refundRequest.Booking.Status,
            paymentStatus =
                refundRequest.Booking.Payment.Status
        });
    }

    // QTV từ chối yêu cầu hoàn tiền
    [HttpPatch("{id}/reject")]
    public async Task<IActionResult> RejectRefund(
        uint id,
        [FromBody] ReviewRefundRequest request
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

        var refundRequest = await _db.RefundRequests
            .Include(r => r.Booking)
            .ThenInclude(b => b.Payment)
            .FirstOrDefaultAsync(r => r.Id == id);

        if (refundRequest is null)
        {
            return NotFound(new
            {
                success = false,
                message =
                    "Không tìm thấy yêu cầu hoàn tiền."
            });
        }

        if (
            refundRequest.Status != "pending" &&
            refundRequest.Status != "approved"
        )
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Yêu cầu này không thể bị từ chối.",
                currentStatus = refundRequest.Status
            });
        }

        var now = DateTime.Now;

        refundRequest.Status = "rejected";
        refundRequest.AdminNote =
            string.IsNullOrWhiteSpace(request.AdminNote)
                ? "QTV đã từ chối yêu cầu hoàn tiền."
                : request.AdminNote.Trim();

        refundRequest.ResolvedBy = adminId;
        refundRequest.ResolvedAt = now;

        // Mở lại đơn vì yêu cầu hoàn tiền không được chấp nhận
        if (
            refundRequest.Booking.Payment is not null &&
            refundRequest.Booking.Payment.Status == "held"
        )
        {
            refundRequest.Booking.Status = "confirmed";
            refundRequest.Booking.UpdatedAt = now;
        }

        await _db.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã từ chối yêu cầu hoàn tiền.",
            refundRequest = new
            {
                refundRequest.Id,
                refundRequest.BookingId,
                refundRequest.RefundAmount,
                refundRequest.Status,
                refundRequest.AdminNote,
                refundRequest.ResolvedAt
            },
            bookingStatus = refundRequest.Booking.Status
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

public sealed class ReviewRefundRequest
{
    [StringLength(
        255,
        ErrorMessage = "Ghi chú không được vượt quá 255 ký tự."
    )]
    public string? AdminNote { get; set; }
}
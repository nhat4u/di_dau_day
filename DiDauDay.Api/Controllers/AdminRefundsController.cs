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
    private static readonly string[] AllowedStatuses =
    [
        "pending",
        "approved",
        "rejected",
        "completed"
    ];

    private readonly DiDauDayDbContext _db;

    public AdminRefundsController(DiDauDayDbContext db)
    {
        _db = db;
    }

    // QTV xem danh sách yêu cầu hoàn tiền.
    [HttpGet]
    public async Task<IActionResult> GetRefundRequests(
        [FromQuery] string? status
    )
    {
        var normalizedStatus = string.IsNullOrWhiteSpace(status)
            ? null
            : status.Trim().ToLowerInvariant();

        if (
            normalizedStatus is not null &&
            !AllowedStatuses.Contains(normalizedStatus)
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Trạng thái yêu cầu hoàn tiền không hợp lệ."
            });
        }

        var query = _db.RefundRequests
            .AsNoTracking()
            .Include(r => r.Booking)
                .ThenInclude(b => b.Homestay)
            .Include(r => r.Booking)
                .ThenInclude(b => b.Payment)
            .Include(r => r.RequestedByNavigation)
            .Include(r => r.ResolvedByNavigation)
            .AsQueryable();

        if (normalizedStatus is not null)
        {
            query = query.Where(r => r.Status == normalizedStatus);
        }

        var entities = await query
            .OrderByDescending(r => r.CreatedAt)
            .ToListAsync();

        var refundRequests = entities
            .Select(BuildAdminResponse)
            .ToList();

        return Ok(new
        {
            success = true,
            total = refundRequests.Count,
            refundRequests
        });
    }

    // Một lần bấm duyệt sẽ hoàn tất hoàn tiền và cập nhật toàn bộ trạng thái.
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
                message = "Không tìm thấy yêu cầu hoàn tiền."
            });
        }

        // Cho phép xử lý nốt dữ liệu cũ đang ở trạng thái approved.
        if (
            refundRequest.Status != "pending" &&
            refundRequest.Status != "approved"
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Yêu cầu này đã được xử lý.",
                currentStatus = refundRequest.Status
            });
        }

        var payment = refundRequest.Booking.Payment;

        if (payment is null || payment.Status != "held")
        {
            return BadRequest(new
            {
                success = false,
                message = "Khoản tiền không còn ở trạng thái tạm giữ."
            });
        }

        decimal approvedAmount;

        if (refundRequest.Reason == "guest_cancelled")
        {
            var policy = CalculateGuestCancellationPolicy(
                refundRequest.CreatedAt,
                refundRequest.Booking.CheckIn,
                payment.Amount
            );

            if (!policy.CanRefund)
            {
                return BadRequest(new
                {
                    success = false,
                    message = "Yêu cầu hủy này không thuộc chính sách hoàn tiền."
                });
            }

            approvedAmount = policy.RefundAmount;
        }
        else
        {
            approvedAmount = request.RefundAmount
                ?? refundRequest.RefundAmount;

            if (
                approvedAmount <= 0 ||
                approvedAmount > payment.Amount
            )
            {
                return BadRequest(new
                {
                    success = false,
                    message = $"Số tiền hoàn phải lớn hơn 0 và không vượt quá {payment.Amount:N0}đ."
                });
            }

            approvedAmount = Math.Round(
                approvedAmount,
                0,
                MidpointRounding.AwayFromZero
            );
        }

        var now = DateTime.Now;

        refundRequest.RefundAmount = approvedAmount;
        refundRequest.Status = "completed";
        refundRequest.AdminNote = string.IsNullOrWhiteSpace(request.AdminNote)
            ? "QTV đã duyệt và hoàn tiền."
            : request.AdminNote.Trim();
        refundRequest.ResolvedBy = adminId;
        refundRequest.ResolvedAt = now;

        refundRequest.Booking.Status = "refunded";
        refundRequest.Booking.UpdatedAt = now;
        payment.Status = "refunded";

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
            paymentStatus = payment.Status
        });
    }

    // QTV từ chối yêu cầu hoàn tiền.
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

        if (string.IsNullOrWhiteSpace(request.AdminNote))
        {
            return BadRequest(new
            {
                success = false,
                message = "Vui lòng nhập lý do từ chối để khách hàng biết."
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
                message = "Không tìm thấy yêu cầu hoàn tiền."
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
                message = "Yêu cầu này không thể bị từ chối.",
                currentStatus = refundRequest.Status
            });
        }

        var now = DateTime.Now;

        refundRequest.Status = "rejected";
        refundRequest.AdminNote = request.AdminNote.Trim();
        refundRequest.ResolvedBy = adminId;
        refundRequest.ResolvedAt = now;

        // Yêu cầu bị từ chối nên mở lại đơn đã thanh toán.
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

    private static object BuildAdminResponse(RefundRequest request)
    {
        var paymentAmount = request.Booking.Payment?.Amount
            ?? request.Booking.TotalAmount;
        var hoursBeforeCheckIn =
            (request.Booking.CheckIn - request.CreatedAt).TotalHours;

        int? refundPercentage = null;
        string policyLabel;

        if (request.Reason == "guest_cancelled")
        {
            var policy = CalculateGuestCancellationPolicy(
                request.CreatedAt,
                request.Booking.CheckIn,
                paymentAmount
            );
            refundPercentage = policy.RefundPercentage;
            policyLabel = policy.PolicyLabel;
        }
        else
        {
            policyLabel = "Sự cố phòng hoặc dịch vụ: QTV quyết định số tiền hoàn.";
        }

        return new
        {
            request.Id,
            request.BookingId,
            request.Booking.BookingCode,
            homestayName = request.Booking.Homestay.Name,
            request.Booking.BookingType,
            request.Booking.CheckIn,
            request.Booking.CheckOut,
            request.Booking.TotalAmount,
            paymentAmount,
            request.Reason,
            request.Description,
            request.RefundAmount,
            request.Status,
            request.AdminNote,
            request.CreatedAt,
            request.ResolvedAt,
            request.ResolvedBy,
            resolvedByName = request.ResolvedByNavigation?.FullName,
            hoursBeforeCheckIn,
            refundPercentage,
            policyLabel,
            requestedBy = new
            {
                request.RequestedByNavigation.Id,
                request.RequestedByNavigation.FullName,
                request.RequestedByNavigation.Email,
                request.RequestedByNavigation.Phone
            },
            bookingStatus = request.Booking.Status,
            paymentStatus = request.Booking.Payment?.Status
        };
    }

    private static GuestCancellationPolicy CalculateGuestCancellationPolicy(
        DateTime requestedAt,
        DateTime checkIn,
        decimal paymentAmount
    )
    {
        var hoursBeforeCheckIn = (checkIn - requestedAt).TotalHours;

        if (hoursBeforeCheckIn >= 120)
        {
            return new GuestCancellationPolicy(
                CanRefund: true,
                RefundPercentage: 100,
                RefundAmount: paymentAmount,
                PolicyLabel: "Hủy trước ít nhất 5 ngày: hoàn 100%."
            );
        }

        if (hoursBeforeCheckIn >= 48)
        {
            return new GuestCancellationPolicy(
                CanRefund: true,
                RefundPercentage: 50,
                RefundAmount: Math.Round(
                    paymentAmount * 0.5m,
                    0,
                    MidpointRounding.AwayFromZero
                ),
                PolicyLabel: "Hủy trước từ 2 đến dưới 5 ngày: hoàn 50%."
            );
        }

        return new GuestCancellationPolicy(
            CanRefund: false,
            RefundPercentage: 0,
            RefundAmount: 0,
            PolicyLabel: "Hủy dưới 2 ngày: không thuộc chính sách hoàn tiền."
        );
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }

    private sealed record GuestCancellationPolicy(
        bool CanRefund,
        int RefundPercentage,
        decimal RefundAmount,
        string PolicyLabel
    );
}

public sealed class ReviewRefundRequest
{
    public decimal? RefundAmount { get; set; }

    [StringLength(
        1000,
        ErrorMessage = "Ghi chú không được vượt quá 1000 ký tự."
    )]
    public string? AdminNote { get; set; }
}

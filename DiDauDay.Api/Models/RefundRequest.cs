using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class RefundRequest
{
    public uint Id { get; set; }

    public uint BookingId { get; set; }

    public uint RequestedBy { get; set; }

    public string Reason { get; set; } = null!;

    public string? Description { get; set; }

    public decimal RefundAmount { get; set; }

    public string Status { get; set; } = null!;

    public string? AdminNote { get; set; }

    public uint? ResolvedBy { get; set; }

    public DateTime? ResolvedAt { get; set; }

    public DateTime CreatedAt { get; set; }

    public virtual Booking Booking { get; set; } = null!;

    public virtual User RequestedByNavigation { get; set; } = null!;

    public virtual User? ResolvedByNavigation { get; set; }
}

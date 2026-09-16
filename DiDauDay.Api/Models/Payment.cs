using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class Payment
{
    public uint Id { get; set; }

    public uint BookingId { get; set; }

    public string? TransactionCode { get; set; }

    public string PaymentMethod { get; set; } = null!;

    public decimal Amount { get; set; }

    public string Status { get; set; } = null!;

    public DateTime? PaidAt { get; set; }

    public DateTime CreatedAt { get; set; }

    public virtual Booking Booking { get; set; } = null!;
}

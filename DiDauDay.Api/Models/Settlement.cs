using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class Settlement
{
    public uint Id { get; set; }

    public uint BookingId { get; set; }

    public uint OwnerId { get; set; }

    public decimal GrossAmount { get; set; }

    public decimal PlatformFee { get; set; }

    public decimal OwnerAmount { get; set; }

    public string Status { get; set; } = null!;

    public DateTime? SettledAt { get; set; }

    public DateTime CreatedAt { get; set; }

    public virtual Booking Booking { get; set; } = null!;

    public virtual User Owner { get; set; } = null!;
}

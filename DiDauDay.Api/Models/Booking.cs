using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class Booking
{
    public uint Id { get; set; }

    public string BookingCode { get; set; } = null!;

    public uint GuestId { get; set; }

    public uint HomestayId { get; set; }

    public string BookingType { get; set; } = null!;

    public DateTime CheckIn { get; set; }

    public DateTime CheckOut { get; set; }

    public byte GuestCount { get; set; }

    public decimal TotalAmount { get; set; }

    public string Status { get; set; } = null!;

    public DateTime? ExpiresAt { get; set; }

    public DateTime CreatedAt { get; set; }

    public DateTime UpdatedAt { get; set; }

    public virtual User Guest { get; set; } = null!;

    public virtual Homestay Homestay { get; set; } = null!;

    public virtual Payment? Payment { get; set; }

    public virtual ICollection<RefundRequest> RefundRequests { get; set; } = new List<RefundRequest>();

    public virtual Settlement? Settlement { get; set; }

    public virtual ICollection<WalletTransaction> WalletTransactions { get; set; } = new List<WalletTransaction>();
}

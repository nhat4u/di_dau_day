using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class WalletTransaction
{
    public uint Id { get; set; }

    public uint WalletId { get; set; }

    public uint? BookingId { get; set; }

    public string TransactionType { get; set; } = null!;

    public string Direction { get; set; } = null!;

    public decimal Amount { get; set; }

    public decimal BalanceAfter { get; set; }

    public string? Description { get; set; }

    public string Status { get; set; } = null!;

    public DateTime CreatedAt { get; set; }

    public virtual Booking? Booking { get; set; }

    public virtual Wallet Wallet { get; set; } = null!;
}

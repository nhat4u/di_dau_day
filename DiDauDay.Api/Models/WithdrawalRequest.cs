using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class WithdrawalRequest
{
    public uint Id { get; set; }

    public uint WalletId { get; set; }

    public decimal Amount { get; set; }

    public string BankName { get; set; } = null!;

    public string BankAccount { get; set; } = null!;

    public string BankAccountName { get; set; } = null!;

    public string Status { get; set; } = null!;

    public string? AdminNote { get; set; }

    public uint? ProcessedBy { get; set; }

    public DateTime RequestedAt { get; set; }

    public DateTime? ProcessedAt { get; set; }

    public virtual User? ProcessedByNavigation { get; set; }

    public virtual Wallet Wallet { get; set; } = null!;
}

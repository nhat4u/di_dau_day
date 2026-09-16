using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class Wallet
{
    public uint Id { get; set; }

    public uint UserId { get; set; }

    public decimal PendingBalance { get; set; }

    public decimal AvailableBalance { get; set; }

    public decimal TotalEarned { get; set; }

    public DateTime UpdatedAt { get; set; }

    public virtual User User { get; set; } = null!;

    public virtual ICollection<WalletTransaction> WalletTransactions { get; set; } = new List<WalletTransaction>();

    public virtual ICollection<WithdrawalRequest> WithdrawalRequests { get; set; } = new List<WithdrawalRequest>();
}

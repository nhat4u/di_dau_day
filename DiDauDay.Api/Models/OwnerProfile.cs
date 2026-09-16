using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class OwnerProfile
{
    public uint Id { get; set; }

    public uint UserId { get; set; }

    public string CitizenId { get; set; } = null!;

    public string Address { get; set; } = null!;

    public string BankName { get; set; } = null!;

    public string BankAccount { get; set; } = null!;

    public string BankAccountName { get; set; } = null!;

    public DateTime CreatedAt { get; set; }

    public virtual User User { get; set; } = null!;
}

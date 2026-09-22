using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class ProfileChangeRequest
{
    public uint Id { get; set; }

    public uint OwnerId { get; set; }

    public string Reason { get; set; } = null!;

    public string RequestedInformation { get; set; } = null!;

    public string Status { get; set; } = null!;

    public string? AdminNote { get; set; }

    public uint? ProcessedBy { get; set; }

    public DateTime CreatedAt { get; set; }

    public DateTime? ProcessedAt { get; set; }

    public virtual User Owner { get; set; } = null!;

    public virtual User? ProcessedByNavigation { get; set; }
}

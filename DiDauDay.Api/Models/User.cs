using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class User
{
    public uint Id { get; set; }

    public string FullName { get; set; } = null!;

    public string Email { get; set; } = null!;

    public string Phone { get; set; } = null!;

    public string Password { get; set; } = null!;

    public string Role { get; set; } = null!;

    public string Status { get; set; } = null!;

    public DateTime CreatedAt { get; set; }

    public DateTime UpdatedAt { get; set; }

    public virtual ICollection<Booking> Bookings { get; set; } = new List<Booking>();

    public virtual ICollection<Homestay> Homestays { get; set; } = new List<Homestay>();

    public virtual ICollection<HomestayChangeRequest> HomestayChangeRequestOwners { get; set; } = new List<HomestayChangeRequest>();

    public virtual ICollection<HomestayChangeRequest> HomestayChangeRequestProcessedByNavigations { get; set; } = new List<HomestayChangeRequest>();

    public virtual OwnerProfile? OwnerProfile { get; set; }

    public virtual ICollection<ProfileChangeRequest> ProfileChangeRequestOwners { get; set; } = new List<ProfileChangeRequest>();

    public virtual ICollection<ProfileChangeRequest> ProfileChangeRequestProcessedByNavigations { get; set; } = new List<ProfileChangeRequest>();

    public virtual ICollection<RefundRequest> RefundRequestRequestedByNavigations { get; set; } = new List<RefundRequest>();

    public virtual ICollection<RefundRequest> RefundRequestResolvedByNavigations { get; set; } = new List<RefundRequest>();

    public virtual ICollection<Settlement> Settlements { get; set; } = new List<Settlement>();

    public virtual Wallet? Wallet { get; set; }

    public virtual ICollection<WithdrawalRequest> WithdrawalRequests { get; set; } = new List<WithdrawalRequest>();
}

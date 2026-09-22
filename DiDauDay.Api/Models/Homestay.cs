using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class Homestay
{
    public uint Id { get; set; }

    public uint OwnerId { get; set; }

    public string Name { get; set; } = null!;

    public string Slug { get; set; } = null!;

    public string RoomRank { get; set; } = null!;

    public string Description { get; set; } = null!;

    public string Address { get; set; } = null!;

    public string Province { get; set; } = null!;

    public string TouristDestination { get; set; } = null!;

    public byte MaxGuests { get; set; }

    public decimal PricePerHour { get; set; }

    public byte MinimumHours { get; set; }

    public bool? AutoCheckin { get; set; }

    public bool HasBathtub { get; set; }

    public bool HasBalcony { get; set; }

    public bool HasMiniPool { get; set; }

    public string? AmenitiesJson { get; set; }

    public decimal OvernightPrice { get; set; }

    public string Status { get; set; } = null!;

    public string? RejectionReason { get; set; }

    public DateTime CreatedAt { get; set; }

    public DateTime UpdatedAt { get; set; }

    public bool IsDeleted { get; set; }

    public virtual ICollection<Booking> Bookings { get; set; } = new List<Booking>();

    public virtual ICollection<HomestayImage> HomestayImages { get; set; } = new List<HomestayImage>();

    public virtual ICollection<HomestayChangeRequest> HomestayChangeRequests { get; set; } = new List<HomestayChangeRequest>();

    public virtual HomestayPrice? HomestayPrice { get; set; }

    public virtual User Owner { get; set; } = null!;
}

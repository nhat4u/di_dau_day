using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class HomestayImage
{
    public uint Id { get; set; }

    public uint HomestayId { get; set; }

    public string ImagePath { get; set; } = null!;

    public bool IsCover { get; set; }

    public int SortOrder { get; set; }

    public DateTime CreatedAt { get; set; }

    public virtual Homestay Homestay { get; set; } = null!;
}

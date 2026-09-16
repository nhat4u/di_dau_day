using System;
using System.Collections.Generic;

namespace DiDauDay.Api.Models;

public partial class HomestayPrice
{
    public uint Id { get; set; }

    public uint HomestayId { get; set; }

    public decimal PriceFirst2Hours { get; set; }

    public decimal PriceCombo4Hours { get; set; }

    public decimal PriceExtraHour { get; set; }

    public decimal PriceOvernightWeekday { get; set; }

    public decimal PriceOvernightWeekend { get; set; }

    public decimal PriceDayNightWeekday { get; set; }

    public decimal PriceDayNightWeekend { get; set; }

    public decimal PriceDayWeekday { get; set; }

    public decimal PriceDayWeekend { get; set; }

    public DateTime CreatedAt { get; set; }

    public DateTime UpdatedAt { get; set; }

    public virtual Homestay Homestay { get; set; } = null!;
}

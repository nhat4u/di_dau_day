using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/[controller]")]
public class HomestaysController : ControllerBase
{
    private readonly DiDauDayDbContext _context;

    public HomestaysController(DiDauDayDbContext context)
    {
        _context = context;
    }

    [HttpGet]
    public async Task<IActionResult> GetAll(
        [FromQuery] string? province,
        [FromQuery] string? destination,
        [FromQuery] byte? guests
    )
    {
        if (guests.HasValue &&
            (guests.Value < 1 || guests.Value > 4))
        {
            return BadRequest(new
            {
                success = false,
                message = "Số khách phải từ 1 đến 4."
            });
        }

        var query = _context.Homestays
            .AsNoTracking()
            .Where(h =>
                !h.IsDeleted &&
                h.Status == "approved"
            );

        if (!string.IsNullOrWhiteSpace(province))
        {
            var value = province.Trim();

            query = query.Where(h =>
                h.Province.Contains(value)
            );
        }

        if (!string.IsNullOrWhiteSpace(destination))
        {
            var value = destination.Trim();

            query = query.Where(h =>
                h.TouristDestination.Contains(value)
            );
        }

        if (guests.HasValue)
        {
            query = query.Where(h =>
                h.MaxGuests >= guests.Value
            );
        }

        var rows = await query
            .OrderByDescending(h => h.CreatedAt)
            .Select(h => new
            {
                Id = h.Id,
                Name = h.Name,
                Slug = h.Slug,
                RoomRank = h.RoomRank,
                Province = h.Province,
                TouristDestination =
                    h.TouristDestination,
                MaxGuests = h.MaxGuests,
                PriceFrom = h.PricePerHour,
                PriceTo = h.OvernightPrice,

                CoverImagePath = h.HomestayImages
                    .OrderByDescending(i => i.IsCover)
                    .ThenBy(i => i.SortOrder)
                    .Select(i => i.ImagePath)
                    .FirstOrDefault()
            })
            .ToListAsync();

        var baseUrl =
            $"{Request.Scheme}://{Request.Host}";

        var result = rows.Select(h => new
        {
            h.Id,
            h.Name,
            h.Slug,
            h.RoomRank,
            h.Province,
            h.TouristDestination,
            h.MaxGuests,
            h.PriceFrom,
            h.PriceTo,

            CoverImageUrl = BuildImageUrl(
                h.CoverImagePath,
                baseUrl
            )
        });

        return Ok(new
        {
            success = true,
            total = rows.Count,
            homestays = result
        });
    }

    [HttpGet("{slug}")]
    public async Task<IActionResult> GetBySlug(
        string slug
    )
    {
        var homestay = await _context.Homestays
            .AsNoTracking()
            .Where(h =>
                h.Slug == slug &&
                !h.IsDeleted &&
                h.Status == "approved"
            )
            .Select(h => new
            {
                Id = h.Id,
                Name = h.Name,
                Slug = h.Slug,
                RoomRank = h.RoomRank,
                Description = h.Description,
                Address = h.Address,
                Province = h.Province,
                TouristDestination =
                    h.TouristDestination,
                MaxGuests = h.MaxGuests,
                PricePerHour = h.PricePerHour,
                MinimumHours = h.MinimumHours,
                OvernightPrice = h.OvernightPrice,
                AutoCheckin = h.AutoCheckin ?? true,
                HasBathtub = h.HasBathtub,
                HasBalcony = h.HasBalcony,
                HasMiniPool = h.HasMiniPool,

                Owner = new
                {
                    Id = h.Owner.Id,
                    FullName = h.Owner.FullName
                },

                DetailedPrices = h.HomestayPrice == null
                    ? null
                    : new
                    {
                        h.HomestayPrice.PriceFirst2Hours,
                        h.HomestayPrice.PriceCombo4Hours,
                        h.HomestayPrice.PriceExtraHour,
                        h.HomestayPrice
                            .PriceOvernightWeekday,
                        h.HomestayPrice
                            .PriceOvernightWeekend,
                        h.HomestayPrice
                            .PriceDayNightWeekday,
                        h.HomestayPrice
                            .PriceDayNightWeekend,
                        h.HomestayPrice
                            .PriceDayWeekday,
                        h.HomestayPrice
                            .PriceDayWeekend
                    },

                Images = h.HomestayImages
                    .OrderByDescending(i => i.IsCover)
                    .ThenBy(i => i.SortOrder)
                    .Select(i => new
                    {
                        Id = i.Id,
                        ImagePath = i.ImagePath,
                        IsCover = i.IsCover,
                        SortOrder = i.SortOrder
                    })
                    .ToList()
            })
            .FirstOrDefaultAsync();

        if (homestay == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        var baseUrl =
            $"{Request.Scheme}://{Request.Host}";

        var images = homestay.Images.Select(i => new
        {
            i.Id,
            i.IsCover,
            i.SortOrder,

            ImageUrl = BuildImageUrl(
                i.ImagePath,
                baseUrl
            )
        });

        var optionalAmenities = new List<string>();

        if (homestay.HasBathtub)
        {
            optionalAmenities.Add("Bồn tắm");
        }

        if (homestay.HasBalcony)
        {
            optionalAmenities.Add("Ban công");
        }

        if (homestay.HasMiniPool)
        {
            optionalAmenities.Add("Bể bơi mini");
        }

        return Ok(new
        {
            success = true,

            homestay = new
            {
                homestay.Id,
                homestay.Name,
                homestay.Slug,
                homestay.RoomRank,
                homestay.Description,
                homestay.Address,
                homestay.Province,
                homestay.TouristDestination,
                homestay.MaxGuests,
                homestay.PricePerHour,
                homestay.MinimumHours,
                homestay.OvernightPrice,
                homestay.AutoCheckin,
                homestay.Owner,

                DefaultAmenities = new[]
                {
                    "Wifi",
                    "Điều hòa",
                    "Bếp riêng",
                    "Máy giặt",
                    "Bãi đỗ xe",
                    "Máy chiếu Netflix",
                    "Gương toàn thân",
                    "Board game",
                    "Nhà vệ sinh khép kín",
                    "Tự check-in/out"
                },

                OptionalAmenities =
                    optionalAmenities,

                homestay.DetailedPrices,
                Images = images
            }
        });
    }

    private static string? BuildImageUrl(
        string? imagePath,
        string baseUrl
    )
    {
        if (string.IsNullOrWhiteSpace(imagePath))
        {
            return null;
        }

        if (Uri.TryCreate(
            imagePath,
            UriKind.Absolute,
            out _
        ))
        {
            return imagePath;
        }

        var normalizedPath = imagePath.StartsWith("/")
            ? imagePath
            : $"/{imagePath}";

        return $"{baseUrl}{normalizedPath}";
    }
}
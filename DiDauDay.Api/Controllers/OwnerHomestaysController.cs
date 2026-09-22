using System.ComponentModel.DataAnnotations;
using System.Globalization;
using System.Security.Claims;
using System.Text;
using System.Text.RegularExpressions;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Authorize(Roles = "owner")]
[Route("api/owner/homestays")]
public class OwnerHomestaysController : ControllerBase
{
    private const int MinimumImagesToPublish = 3;
    private const int MaximumImages = 10;

    private readonly DiDauDayDbContext _context;

    public OwnerHomestaysController(DiDauDayDbContext context)
    {
        _context = context;
    }

    // Danh sách homestay của chủ nhà đang đăng nhập.
    [HttpGet]
    public async Task<IActionResult> GetMine()
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var rows = await _context.Homestays
            .AsNoTracking()
            .Where(h => h.OwnerId == ownerId && !h.IsDeleted)
            .OrderByDescending(h => h.UpdatedAt)
            .Select(h => new
            {
                h.Id,
                h.Name,
                h.Slug,
                h.RoomRank,
                h.Description,
                h.Address,
                h.Province,
                h.TouristDestination,
                h.MaxGuests,
                h.PricePerHour,
                h.MinimumHours,
                h.AutoCheckin,
                h.HasBathtub,
                h.HasBalcony,
                h.HasMiniPool,
                h.AmenitiesJson,
                h.OvernightPrice,
                h.Status,
                h.CreatedAt,
                h.UpdatedAt,
                ImageCount = h.HomestayImages.Count,
                CoverImagePath = h.HomestayImages
                    .OrderByDescending(i => i.IsCover)
                    .ThenBy(i => i.SortOrder)
                    .Select(i => i.ImagePath)
                    .FirstOrDefault(),
                HasPriceTable = h.HomestayPrice != null,
                PendingChange = h.HomestayChangeRequests
                    .Where(r => r.Status == "pending")
                    .OrderByDescending(r => r.CreatedAt)
                    .Select(r => new
                    {
                        r.Id,
                        r.RequestType,
                        r.CreatedAt
                    })
                    .FirstOrDefault(),
                LatestChange = h.HomestayChangeRequests
                    .OrderByDescending(r => r.CreatedAt)
                    .Select(r => new
                    {
                        r.Id,
                        r.RequestType,
                        r.Status,
                        r.AdminNote,
                        r.CreatedAt,
                        r.ProcessedAt
                    })
                    .FirstOrDefault()
            })
            .ToListAsync();

        var baseUrl = $"{Request.Scheme}://{Request.Host}";
        var homestays = rows.Select(h => new
        {
            h.Id,
            h.Name,
            h.Slug,
            h.RoomRank,
            h.Description,
            h.Address,
            h.Province,
            h.TouristDestination,
            h.MaxGuests,
            h.PricePerHour,
            h.MinimumHours,
            h.AutoCheckin,
            h.HasBathtub,
            h.HasBalcony,
            h.HasMiniPool,
            Amenities = HomestayAmenityCatalog.ResolveStored(
                h.AmenitiesJson,
                h.HasBathtub,
                h.HasBalcony,
                h.HasMiniPool
            ),
            h.OvernightPrice,
            h.Status,
            h.ImageCount,
            h.HasPriceTable,
            h.PendingChange,
            h.LatestChange,
            h.CreatedAt,
            h.UpdatedAt,
            CoverImageUrl = BuildImageUrl(
                h.CoverImagePath,
                baseUrl
            ),
            CanPublish =
                h.Status == "draft" &&
                h.HasPriceTable &&
                h.ImageCount >= MinimumImagesToPublish &&
                h.ImageCount <= MaximumImages
        });

        return Ok(new
        {
            success = true,
            total = rows.Count,
            homestays
        });
    }

    // Xem toàn bộ dữ liệu thiết lập của một homestay.
    [HttpGet("{id}")]
    public async Task<IActionResult> GetById(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var homestay = await _context.Homestays
            .AsNoTracking()
            .Where(h =>
                h.Id == id &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            )
            .Select(h => new
            {
                h.Id,
                h.OwnerId,
                h.Name,
                h.Slug,
                h.RoomRank,
                h.Description,
                h.Address,
                h.Province,
                h.TouristDestination,
                h.MaxGuests,
                h.PricePerHour,
                h.MinimumHours,
                h.AutoCheckin,
                h.HasBathtub,
                h.HasBalcony,
                h.HasMiniPool,
                h.AmenitiesJson,
                h.OvernightPrice,
                h.Status,
                h.RejectionReason,
                h.CreatedAt,
                h.UpdatedAt,
                Prices = h.HomestayPrice == null
                    ? null
                    : new
                    {
                        h.HomestayPrice.Id,
                        h.HomestayPrice.PriceFirst2Hours,
                        h.HomestayPrice.PriceCombo4Hours,
                        h.HomestayPrice.PriceExtraHour,
                        h.HomestayPrice.PriceOvernightWeekday,
                        h.HomestayPrice.PriceOvernightWeekend,
                        h.HomestayPrice.PriceDayNightWeekday,
                        h.HomestayPrice.PriceDayNightWeekend,
                        h.HomestayPrice.PriceDayWeekday,
                        h.HomestayPrice.PriceDayWeekend
                    },
                Images = h.HomestayImages
                    .OrderByDescending(i => i.IsCover)
                    .ThenBy(i => i.SortOrder)
                    .Select(i => new
                    {
                        i.Id,
                        i.ImagePath,
                        i.IsCover,
                        i.SortOrder,
                        i.CreatedAt
                    })
                    .ToList(),
                PendingChange = h.HomestayChangeRequests
                    .Where(r => r.Status == "pending")
                    .OrderByDescending(r => r.CreatedAt)
                    .Select(r => new
                    {
                        r.Id,
                        r.RequestType,
                        r.Reason,
                        r.CreatedAt
                    })
                    .FirstOrDefault()
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

        var baseUrl = $"{Request.Scheme}://{Request.Host}";
        var images = homestay.Images.Select(i => new
        {
            i.Id,
            i.ImagePath,
            i.IsCover,
            i.SortOrder,
            i.CreatedAt,
            ImageUrl = BuildImageUrl(i.ImagePath, baseUrl)
        });

        return Ok(new
        {
            success = true,
            homestay = new
            {
                homestay.Id,
                homestay.OwnerId,
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
                homestay.AutoCheckin,
                homestay.HasBathtub,
                homestay.HasBalcony,
                homestay.HasMiniPool,
                Amenities = HomestayAmenityCatalog.ResolveStored(
                    homestay.AmenitiesJson,
                    homestay.HasBathtub,
                    homestay.HasBalcony,
                    homestay.HasMiniPool
                ),
                homestay.OvernightPrice,
                homestay.Status,
                homestay.RejectionReason,
                homestay.Prices,
                Images = images,
                ImageCount = homestay.Images.Count,
                homestay.PendingChange,
                homestay.CreatedAt,
                homestay.UpdatedAt
            }
        });
    }

    // Bước 1: tạo bản nháp từ thông tin cơ bản.
    [HttpPost]
    public async Task<IActionResult> Create(
        [FromBody] SaveHomestayRequest request
    )
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var owner = await _context.Users
            .AsNoTracking()
            .Where(u => u.Id == ownerId)
            .Select(u => new
            {
                u.Status,
                HasProfile = u.OwnerProfile != null
            })
            .FirstOrDefaultAsync();

        if (owner == null || owner.Status != "approved")
        {
            return BadRequest(new
            {
                success = false,
                message = "Tài khoản chủ homestay chưa được QTV duyệt."
            });
        }

        if (!owner.HasProfile)
        {
            return BadRequest(new
            {
                success = false,
                message = "Bạn phải hoàn thành hồ sơ chủ homestay trước."
            });
        }

        var now = DateTime.Now;
        var amenities = HomestayAmenityCatalog.ResolveRequested(
            request.Amenities,
            request.HasBathtub,
            request.HasBalcony,
            request.HasMiniPool
        );
        var homestay = new Homestay
        {
            OwnerId = ownerId,
            Name = request.Name.Trim(),
            Slug = await GenerateUniqueSlugAsync(request.Name),
            RoomRank = request.RoomRank.Trim().ToLowerInvariant(),
            Description = request.Description.Trim(),
            Address = request.Address.Trim(),
            Province = request.Province.Trim(),
            TouristDestination = request.TouristDestination.Trim(),
            MaxGuests = request.MaxGuests,
            PricePerHour = 0,
            MinimumHours = 2,
            AutoCheckin = true,
            HasBathtub = HomestayAmenityCatalog.Contains(
                amenities,
                "Bồn tắm"
            ),
            HasBalcony = HomestayAmenityCatalog.Contains(
                amenities,
                "Ban công"
            ),
            HasMiniPool = HomestayAmenityCatalog.Contains(
                amenities,
                "Hồ bơi mini"
            ),
            AmenitiesJson = HomestayAmenityCatalog.Serialize(amenities),
            OvernightPrice = 0,
            Status = "draft",
            RejectionReason = null,
            CreatedAt = now,
            UpdatedAt = now,
            IsDeleted = false
        };

        _context.Homestays.Add(homestay);
        await _context.SaveChangesAsync();

        return StatusCode(StatusCodes.Status201Created, new
        {
            success = true,
            message = "Đã lưu thông tin và tạo bản nháp homestay.",
            homestay = new
            {
                homestay.Id,
                homestay.Name,
                homestay.Slug,
                homestay.Status
            }
        });
    }

    // Chủ nhà chỉ được sửa trực tiếp khi homestay còn là bản nháp.
    [HttpPut("{id}")]
    public async Task<IActionResult> Update(
        uint id,
        [FromBody] SaveHomestayRequest request
    )
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var homestay = await _context.Homestays
            .FirstOrDefaultAsync(h =>
                h.Id == id &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        if (homestay == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        if (homestay.Status != "draft")
        {
            return Conflict(new
            {
                success = false,
                message = "Homestay đã đăng. Vui lòng gửi yêu cầu thay đổi để QTV duyệt."
            });
        }

        ApplyBasicInformation(homestay, request);
        homestay.Slug = await GenerateUniqueSlugAsync(
            request.Name,
            homestay.Id
        );
        homestay.UpdatedAt = DateTime.Now;

        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã cập nhật thông tin bản nháp.",
            homestay = new
            {
                homestay.Id,
                homestay.Name,
                homestay.Slug,
                homestay.Status
            }
        });
    }

    // Bước cuối: chỉ đăng khi có đủ bảng giá và từ 3 đến 10 ảnh.
    [HttpPost("{id}/publish")]
    public async Task<IActionResult> Publish(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var homestay = await _context.Homestays
            .Include(h => h.HomestayPrice)
            .Include(h => h.HomestayImages)
            .FirstOrDefaultAsync(h =>
                h.Id == id &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        if (homestay == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        if (homestay.Status != "draft")
        {
            return BadRequest(new
            {
                success = false,
                message = "Chỉ có thể đăng homestay đang ở trạng thái bản nháp."
            });
        }

        if (
            homestay.HomestayPrice == null ||
            !HasValidPriceTable(homestay.HomestayPrice)
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Vui lòng nhập đầy đủ và đúng bảng giá trước khi đăng."
            });
        }

        var imageCount = homestay.HomestayImages.Count;
        if (imageCount < MinimumImagesToPublish || imageCount > MaximumImages)
        {
            return BadRequest(new
            {
                success = false,
                message = $"Homestay phải có từ {MinimumImagesToPublish} đến {MaximumImages} ảnh trước khi đăng."
            });
        }

        if (homestay.HomestayImages.Count(i => i.IsCover) != 1)
        {
            return BadRequest(new
            {
                success = false,
                message = "Homestay phải có đúng một ảnh bìa."
            });
        }

        SyncSummaryPrices(homestay, homestay.HomestayPrice);
        homestay.Status = "approved";
        homestay.RejectionReason = null;
        homestay.UpdatedAt = DateTime.Now;

        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đăng homestay thành công. Homestay đã hiển thị cho khách hàng.",
            homestay = new
            {
                homestay.Id,
                homestay.Name,
                homestay.Slug,
                homestay.Status
            }
        });
    }

    // Bản nháp có thể xóa trực tiếp; home đã đăng phải gửi yêu cầu đóng.
    [HttpDelete("{id}")]
    public async Task<IActionResult> Delete(uint id)
    {
        if (!TryGetCurrentUserId(out var ownerId))
        {
            return Unauthorized(new
            {
                success = false,
                message = "JWT không hợp lệ."
            });
        }

        var homestay = await _context.Homestays
            .FirstOrDefaultAsync(h =>
                h.Id == id &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        if (homestay == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        if (homestay.Status != "draft")
        {
            return Conflict(new
            {
                success = false,
                message = "Homestay đã đăng. Vui lòng gửi yêu cầu ngừng hoạt động để QTV duyệt."
            });
        }

        homestay.IsDeleted = true;
        homestay.UpdatedAt = DateTime.Now;
        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã xóa bản nháp homestay."
        });
    }

    internal static void ApplyBasicInformation(
        Homestay homestay,
        SaveHomestayRequest request
    )
    {
        homestay.Name = request.Name.Trim();
        homestay.RoomRank = request.RoomRank.Trim().ToLowerInvariant();
        homestay.Description = request.Description.Trim();
        homestay.Address = request.Address.Trim();
        homestay.Province = request.Province.Trim();
        homestay.TouristDestination = request.TouristDestination.Trim();
        homestay.MaxGuests = request.MaxGuests;
        homestay.MinimumHours = 2;
        homestay.AutoCheckin = true;
        var amenities = HomestayAmenityCatalog.ResolveRequested(
            request.Amenities,
            request.HasBathtub,
            request.HasBalcony,
            request.HasMiniPool
        );
        homestay.HasBathtub = HomestayAmenityCatalog.Contains(
            amenities,
            "Bồn tắm"
        );
        homestay.HasBalcony = HomestayAmenityCatalog.Contains(
            amenities,
            "Ban công"
        );
        homestay.HasMiniPool = HomestayAmenityCatalog.Contains(
            amenities,
            "Hồ bơi mini"
        );
        homestay.AmenitiesJson = HomestayAmenityCatalog.Serialize(
            amenities
        );
    }

    internal static void SyncSummaryPrices(
        Homestay homestay,
        HomestayPrice prices
    )
    {
        homestay.PricePerHour = prices.PriceFirst2Hours;
        homestay.OvernightPrice = new[]
        {
            prices.PriceFirst2Hours,
            prices.PriceCombo4Hours,
            prices.PriceOvernightWeekday,
            prices.PriceOvernightWeekend,
            prices.PriceDayNightWeekday,
            prices.PriceDayNightWeekend,
            prices.PriceDayWeekday,
            prices.PriceDayWeekend
        }.Max();
    }

    internal static bool HasValidPriceTable(HomestayPrice prices)
    {
        var values = new[]
        {
            prices.PriceFirst2Hours,
            prices.PriceCombo4Hours,
            prices.PriceExtraHour,
            prices.PriceOvernightWeekday,
            prices.PriceOvernightWeekend,
            prices.PriceDayNightWeekday,
            prices.PriceDayNightWeekend,
            prices.PriceDayWeekday,
            prices.PriceDayWeekend
        };

        return values.All(value => value >= 1000) &&
            prices.PriceCombo4Hours >= prices.PriceFirst2Hours;
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }

    internal async Task<string> GenerateUniqueSlugAsync(
        string name,
        uint? ignoredId = null
    )
    {
        var baseSlug = CreateSlug(name);
        var slug = baseSlug;
        var number = 2;

        while (await _context.Homestays.AnyAsync(h =>
            h.Slug == slug &&
            (!ignoredId.HasValue || h.Id != ignoredId.Value)
        ))
        {
            slug = $"{baseSlug}-{number}";
            number++;
        }

        return slug;
    }

    internal static string CreateSlug(string value)
    {
        var normalized = value.Normalize(NormalizationForm.FormD);
        var builder = new StringBuilder();

        foreach (var character in normalized)
        {
            var category = CharUnicodeInfo.GetUnicodeCategory(character);
            if (category != UnicodeCategory.NonSpacingMark)
            {
                builder.Append(character);
            }
        }

        var slug = builder
            .ToString()
            .Normalize(NormalizationForm.FormC)
            .ToLowerInvariant()
            .Replace('đ', 'd');

        slug = Regex.Replace(slug, @"[^a-z0-9]+", "-").Trim('-');
        return string.IsNullOrWhiteSpace(slug) ? "homestay" : slug;
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

        if (Uri.TryCreate(imagePath, UriKind.Absolute, out _))
        {
            return imagePath;
        }

        var normalizedPath = imagePath.StartsWith("/")
            ? imagePath
            : $"/{imagePath}";

        return $"{baseUrl}{normalizedPath}";
    }
}

public sealed class SaveHomestayRequest : IValidatableObject
{
    [Required(ErrorMessage = "Vui lòng nhập tên homestay.")]
    [StringLength(
        150,
        MinimumLength = 2,
        ErrorMessage = "Tên homestay phải có từ 2 đến 150 ký tự."
    )]
    public string Name { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng chọn hạng phòng.")]
    [RegularExpression(
        "^(standard|deluxe|premium)$",
        ErrorMessage = "Hạng phòng không hợp lệ."
    )]
    public string RoomRank { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập phần giới thiệu.")]
    [StringLength(
        5000,
        MinimumLength = 20,
        ErrorMessage = "Phần giới thiệu phải có từ 20 đến 5000 ký tự."
    )]
    public string Description { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập địa chỉ.")]
    [StringLength(
        255,
        MinimumLength = 5,
        ErrorMessage = "Địa chỉ phải có từ 5 đến 255 ký tự."
    )]
    public string Address { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập tỉnh/thành phố.")]
    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage = "Tỉnh/thành phố không hợp lệ."
    )]
    public string Province { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập điểm du lịch gần homestay.")]
    [StringLength(
        150,
        MinimumLength = 2,
        ErrorMessage = "Điểm du lịch không hợp lệ."
    )]
    public string TouristDestination { get; set; } = string.Empty;

    [Range(
        1,
        4,
        ErrorMessage = "Sức chứa phải từ 1 đến 4 khách."
    )]
    public byte MaxGuests { get; set; }

    public bool HasBathtub { get; set; }

    public bool HasBalcony { get; set; }

    public bool HasMiniPool { get; set; }

    // Null keeps compatibility with requests created by the old frontend.
    // An empty array means the owner intentionally selected no amenities.
    public List<string>? Amenities { get; set; }

    public IEnumerable<ValidationResult> Validate(
        ValidationContext validationContext
    )
    {
        if (Amenities == null)
        {
            yield break;
        }

        if (Amenities.Count > HomestayAmenityCatalog.MaximumAmenities)
        {
            yield return new ValidationResult(
                $"Chỉ được chọn tối đa {HomestayAmenityCatalog.MaximumAmenities} tiện ích.",
                [nameof(Amenities)]
            );
        }

        foreach (var amenity in Amenities)
        {
            if (string.IsNullOrWhiteSpace(amenity))
            {
                yield return new ValidationResult(
                    "Tên tiện ích không được để trống.",
                    [nameof(Amenities)]
                );
                yield break;
            }

            if (amenity.Trim().Length >
                HomestayAmenityCatalog.MaximumAmenityLength)
            {
                yield return new ValidationResult(
                    $"Mỗi tiện ích không được vượt quá {HomestayAmenityCatalog.MaximumAmenityLength} ký tự.",
                    [nameof(Amenities)]
                );
                yield break;
            }
        }

        var normalized = HomestayAmenityCatalog.Normalize(Amenities);
        if (normalized.Count != Amenities.Count)
        {
            yield return new ValidationResult(
                "Danh sách tiện ích có mục bị trùng.",
                [nameof(Amenities)]
            );
        }
    }
}

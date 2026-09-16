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
    private readonly DiDauDayDbContext _context;

    public OwnerHomestaysController(DiDauDayDbContext context)
    {
        _context = context;
    }

    // Danh sách homestay của chủ nhà đang đăng nhập
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

        var homestays = await _context.Homestays
            .AsNoTracking()
            .Where(h => h.OwnerId == ownerId && !h.IsDeleted)
            .OrderByDescending(h => h.CreatedAt)
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
                h.OvernightPrice,
                h.Status,
                h.CreatedAt,
                h.UpdatedAt
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = homestays.Count,
            homestays
        });
    }

    // Xem một homestay của chủ nhà
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
                h.OvernightPrice,
                h.Status,
                h.RejectionReason,
                h.CreatedAt,
                h.UpdatedAt
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

        return Ok(new
        {
            success = true,
            homestay
        });
    }

    // Thêm homestay
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

        var hasProfile = await _context.OwnerProfiles
            .AsNoTracking()
            .AnyAsync(p => p.UserId == ownerId);

        if (!hasProfile)
        {
            return BadRequest(new
            {
                success = false,
                message = "Bạn phải hoàn thành hồ sơ chủ homestay trước."
            });
        }

        var slug = await GenerateUniqueSlugAsync(request.Name);
        var now = DateTime.Now;

        var homestay = new Homestay
        {
            OwnerId = ownerId,
            Name = request.Name.Trim(),
            Slug = slug,
            RoomRank = request.RoomRank.Trim().ToLowerInvariant(),
            Description = request.Description.Trim(),
            Address = request.Address.Trim(),
            Province = request.Province.Trim(),
            TouristDestination =
                request.TouristDestination.Trim(),
            MaxGuests = request.MaxGuests,
            PricePerHour = request.PricePerHour,

            // Quy định đặt tối thiểu 2 giờ
            MinimumHours = 2,

            // Tự check-in/out là tiện ích mặc định
            AutoCheckin = true,

            HasBathtub = request.HasBathtub,
            HasBalcony = request.HasBalcony,
            HasMiniPool = request.HasMiniPool,
            OvernightPrice = request.OvernightPrice,

            // Homestay không cần QTV duyệt
            Status = "approved",

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
            message = "Thêm homestay thành công.",
            homestay = new
            {
                homestay.Id,
                homestay.Name,
                homestay.Slug,
                homestay.Status
            }
        });
    }

    // Sửa homestay
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

        homestay.Name = request.Name.Trim();
        homestay.Slug = await GenerateUniqueSlugAsync(
            request.Name,
            homestay.Id
        );
        homestay.RoomRank =
            request.RoomRank.Trim().ToLowerInvariant();
        homestay.Description = request.Description.Trim();
        homestay.Address = request.Address.Trim();
        homestay.Province = request.Province.Trim();
        homestay.TouristDestination =
            request.TouristDestination.Trim();
        homestay.MaxGuests = request.MaxGuests;
        homestay.PricePerHour = request.PricePerHour;
        homestay.MinimumHours = 2;
        homestay.AutoCheckin = true;
        homestay.HasBathtub = request.HasBathtub;
        homestay.HasBalcony = request.HasBalcony;
        homestay.HasMiniPool = request.HasMiniPool;
        homestay.OvernightPrice = request.OvernightPrice;
        homestay.UpdatedAt = DateTime.Now;

        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Cập nhật homestay thành công.",
            homestay = new
            {
                homestay.Id,
                homestay.Name,
                homestay.Slug,
                homestay.Status
            }
        });
    }

    // Xóa mềm homestay
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

        homestay.IsDeleted = true;
        homestay.UpdatedAt = DateTime.Now;

        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Xóa homestay thành công."
        });
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }

    private async Task<string> GenerateUniqueSlugAsync(
        string name,
        uint? ignoredId = null
    )
    {
        var baseSlug = CreateSlug(name);
        var slug = baseSlug;
        var number = 2;

        while (true)
        {
            bool exists;

            if (ignoredId.HasValue)
            {
                exists = await _context.Homestays.AnyAsync(h =>
                    h.Slug == slug &&
                    h.Id != ignoredId.Value
                );
            }
            else
            {
                exists = await _context.Homestays
                    .AnyAsync(h => h.Slug == slug);
            }

            if (!exists)
            {
                return slug;
            }

            slug = $"{baseSlug}-{number}";
            number++;
        }
    }

    private static string CreateSlug(string value)
    {
        var normalized = value.Normalize(
            NormalizationForm.FormD
        );

        var builder = new StringBuilder();

        foreach (var character in normalized)
        {
            var category =
                CharUnicodeInfo.GetUnicodeCategory(character);

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

        slug = Regex.Replace(slug, @"[^a-z0-9]+", "-");
        slug = slug.Trim('-');

        return string.IsNullOrWhiteSpace(slug)
            ? "homestay"
            : slug;
    }
}

public sealed class SaveHomestayRequest
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
        ErrorMessage = "Phần giới thiệu phải có ít nhất 20 ký tự."
    )]
    public string Description { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập địa chỉ.")]
    [StringLength(
        255,
        MinimumLength = 10,
        ErrorMessage = "Địa chỉ phải có ít nhất 10 ký tự."
    )]
    public string Address { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập tỉnh/thành phố.")]
    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage = "Tỉnh/thành phố không hợp lệ."
    )]
    public string Province { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập điểm du lịch.")]
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

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá theo giờ phải lớn hơn 0."
    )]
    public decimal PricePerHour { get; set; }

    public bool HasBathtub { get; set; }

    public bool HasBalcony { get; set; }

    public bool HasMiniPool { get; set; }

    [Range(
        typeof(decimal),
        "1000",
        "1000000000",
        ErrorMessage = "Giá qua đêm phải lớn hơn 0."
    )]
    public decimal OvernightPrice { get; set; }
}
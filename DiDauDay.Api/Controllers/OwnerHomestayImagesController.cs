using System.Security.Claims;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Authorize(Roles = "owner")]
[Route("api/owner/homestays/{homestayId}/images")]
public class OwnerHomestayImagesController : ControllerBase
{
    private const int MaximumImages = 8;
    private const long MaximumFileSize =
        5 * 1024 * 1024;

    private static readonly HashSet<string>
        AllowedExtensions = new(StringComparer.OrdinalIgnoreCase)
        {
            ".jpg",
            ".jpeg",
            ".png",
            ".webp"
        };

    private readonly DiDauDayDbContext _context;
    private readonly IWebHostEnvironment _environment;

    public OwnerHomestayImagesController(
        DiDauDayDbContext context,
        IWebHostEnvironment environment
    )
    {
        _context = context;
        _environment = environment;
    }

    // Xem danh sách ảnh
    [HttpGet]
    public async Task<IActionResult> GetImages(
        uint homestayId
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

        var ownsHomestay = await _context.Homestays
            .AsNoTracking()
            .AnyAsync(h =>
                h.Id == homestayId &&
                h.OwnerId == ownerId &&
                !h.IsDeleted
            );

        if (!ownsHomestay)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy homestay."
            });
        }

        var imageRecords = await _context.HomestayImages
            .AsNoTracking()
            .Where(i => i.HomestayId == homestayId)
            .OrderByDescending(i => i.IsCover)
            .ThenBy(i => i.SortOrder)
            .Select(i => new
            {
                i.Id,
                i.HomestayId,
                i.ImagePath,
                i.IsCover,
                i.SortOrder,
                i.CreatedAt
            })
            .ToListAsync();

        var baseUrl =
            $"{Request.Scheme}://{Request.Host}";

        var images = imageRecords.Select(i => new
        {
            i.Id,
            i.HomestayId,
            i.ImagePath,
            ImageUrl = $"{baseUrl}{i.ImagePath}",
            i.IsCover,
            i.SortOrder,
            i.CreatedAt
        });

        return Ok(new
        {
            success = true,
            total = imageRecords.Count,
            images
        });
    }

    // Tải từ 1 đến 8 ảnh
    [HttpPost]
    [RequestSizeLimit(40 * 1024 * 1024)]
    [RequestFormLimits(
        MultipartBodyLengthLimit = 40 * 1024 * 1024
    )]
    public async Task<IActionResult> UploadImages(
        uint homestayId,
        [FromForm] List<IFormFile> files
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
                h.Id == homestayId &&
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

        if (files == null || files.Count == 0)
        {
            return BadRequest(new
            {
                success = false,
                message = "Vui lòng chọn ít nhất 1 ảnh."
            });
        }

        var existingCount = await _context.HomestayImages
            .CountAsync(i => i.HomestayId == homestayId);

        if (existingCount + files.Count > MaximumImages)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    $"Mỗi homestay chỉ được có tối đa {MaximumImages} ảnh."
            });
        }

        foreach (var file in files)
        {
            if (file.Length == 0)
            {
                return BadRequest(new
                {
                    success = false,
                    message = $"Ảnh {file.FileName} bị rỗng."
                });
            }

            if (file.Length > MaximumFileSize)
            {
                return BadRequest(new
                {
                    success = false,
                    message =
                        $"Ảnh {file.FileName} vượt quá 5MB."
                });
            }

            var extension = Path.GetExtension(
                file.FileName
            );

            if (!AllowedExtensions.Contains(extension))
            {
                return BadRequest(new
                {
                    success = false,
                    message =
                        $"Ảnh {file.FileName} không đúng định dạng JPG, PNG hoặc WEBP."
                });
            }
        }

        var webRootPath = _environment.WebRootPath;

        if (string.IsNullOrWhiteSpace(webRootPath))
        {
            webRootPath = Path.Combine(
                _environment.ContentRootPath,
                "wwwroot"
            );
        }

        var uploadDirectory = Path.Combine(
            webRootPath,
            "uploads",
            "homestays",
            homestayId.ToString()
        );

        Directory.CreateDirectory(uploadDirectory);

        var hasCover = await _context.HomestayImages
            .AnyAsync(i =>
                i.HomestayId == homestayId &&
                i.IsCover
            );

        var lastSortOrder = await _context.HomestayImages
            .Where(i => i.HomestayId == homestayId)
            .Select(i => (int?)i.SortOrder)
            .MaxAsync() ?? 0;

        var savedFilePaths = new List<string>();
        var newImages = new List<HomestayImage>();
        var now = DateTime.Now;

        try
        {
            foreach (var file in files)
            {
                var extension = Path
                    .GetExtension(file.FileName)
                    .ToLowerInvariant();

                var fileName =
                    $"{Guid.NewGuid():N}{extension}";

                var physicalPath = Path.Combine(
                    uploadDirectory,
                    fileName
                );

                await using (var stream = new FileStream(
                    physicalPath,
                    FileMode.CreateNew,
                    FileAccess.Write,
                    FileShare.None
                ))
                {
                    await file.CopyToAsync(stream);
                }

                savedFilePaths.Add(physicalPath);

                var image = new HomestayImage
                {
                    HomestayId = homestayId,
                    ImagePath =
                        $"/uploads/homestays/{homestayId}/{fileName}",
                    IsCover =
                        !hasCover && newImages.Count == 0,
                    SortOrder = ++lastSortOrder,
                    CreatedAt = now
                };

                newImages.Add(image);
                _context.HomestayImages.Add(image);
            }

            homestay.UpdatedAt = now;
            await _context.SaveChangesAsync();
        }
        catch
        {
            foreach (var path in savedFilePaths)
            {
                if (System.IO.File.Exists(path))
                {
                    System.IO.File.Delete(path);
                }
            }

            throw;
        }

        var baseUrl =
            $"{Request.Scheme}://{Request.Host}";

        return StatusCode(StatusCodes.Status201Created, new
        {
            success = true,
            message = $"Đã tải lên {newImages.Count} ảnh.",
            images = newImages.Select(i => new
            {
                i.Id,
                i.ImagePath,
                ImageUrl = $"{baseUrl}{i.ImagePath}",
                i.IsCover,
                i.SortOrder
            })
        });
    }

    // Chọn ảnh bìa
    [HttpPut("{imageId}/cover")]
    public async Task<IActionResult> SetCover(
        uint homestayId,
        uint imageId
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
                h.Id == homestayId &&
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

        var images = await _context.HomestayImages
            .Where(i => i.HomestayId == homestayId)
            .ToListAsync();

        var selectedImage = images
            .FirstOrDefault(i => i.Id == imageId);

        if (selectedImage == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy ảnh."
            });
        }

        foreach (var image in images)
        {
            image.IsCover = image.Id == imageId;
        }

        homestay.UpdatedAt = DateTime.Now;
        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã chọn ảnh bìa.",
            imageId
        });
    }

    // Xóa một ảnh
    [HttpDelete("{imageId}")]
    public async Task<IActionResult> DeleteImage(
        uint homestayId,
        uint imageId
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
                h.Id == homestayId &&
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

        var images = await _context.HomestayImages
            .Where(i => i.HomestayId == homestayId)
            .OrderBy(i => i.SortOrder)
            .ToListAsync();

        var image = images.FirstOrDefault(
            i => i.Id == imageId
        );

        if (image == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy ảnh."
            });
        }

        if (images.Count == 1)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Homestay phải giữ lại ít nhất 1 ảnh."
            });
        }

        if (image.IsCover)
        {
            var newCover = images.First(
                i => i.Id != imageId
            );

            newCover.IsCover = true;
        }

        _context.HomestayImages.Remove(image);
        homestay.UpdatedAt = DateTime.Now;
        await _context.SaveChangesAsync();

        var webRootPath = _environment.WebRootPath;

        if (string.IsNullOrWhiteSpace(webRootPath))
        {
            webRootPath = Path.Combine(
                _environment.ContentRootPath,
                "wwwroot"
            );
        }

        var fileName = Path.GetFileName(
            image.ImagePath
        );

        var physicalPath = Path.Combine(
            webRootPath,
            "uploads",
            "homestays",
            homestayId.ToString(),
            fileName
        );

        if (System.IO.File.Exists(physicalPath))
        {
            System.IO.File.Delete(physicalPath);
        }

        return Ok(new
        {
            success = true,
            message = "Xóa ảnh thành công."
        });
    }

    private bool TryGetCurrentUserId(out uint userId)
    {
        var userIdValue = User.FindFirstValue(
            ClaimTypes.NameIdentifier
        );

        return uint.TryParse(userIdValue, out userId);
    }
}
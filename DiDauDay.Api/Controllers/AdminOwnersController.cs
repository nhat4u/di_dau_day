using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/admin/owners")]
[Authorize(Roles = "admin")]
public class AdminOwnersController : ControllerBase
{
    private static readonly string[] AllowedStatuses =
    [
        "pending",
        "approved",
        "rejected",
        "blocked"
    ];

    private readonly DiDauDayDbContext _context;

    public AdminOwnersController(DiDauDayDbContext context)
    {
        _context = context;
    }

    // QTV xem toàn bộ chủ homestay, có thể lọc trạng thái và tìm kiếm.
    [HttpGet]
    public async Task<IActionResult> GetOwners(
        [FromQuery] string? status,
        [FromQuery] string? search
    )
    {
        string? normalizedStatus = status?
            .Trim()
            .ToLowerInvariant();

        if (
            !string.IsNullOrWhiteSpace(normalizedStatus) &&
            !AllowedStatuses.Contains(normalizedStatus)
        )
        {
            return BadRequest(new
            {
                success = false,
                message = "Trạng thái tài khoản không hợp lệ."
            });
        }

        var query = _context.Users
            .AsNoTracking()
            .Where(u => u.Role == "owner");

        if (!string.IsNullOrWhiteSpace(normalizedStatus))
        {
            query = query.Where(u => u.Status == normalizedStatus);
        }

        if (!string.IsNullOrWhiteSpace(search))
        {
            string keyword = search.Trim();

            query = query.Where(u =>
                u.FullName.Contains(keyword) ||
                u.Email.Contains(keyword) ||
                u.Phone.Contains(keyword) ||
                (
                    u.OwnerProfile != null &&
                    u.OwnerProfile.CitizenId.Contains(keyword)
                )
            );
        }

        var owners = await query
            .OrderBy(u => u.Status == "pending" ? 0 : 1)
            .ThenByDescending(u => u.CreatedAt)
            .Select(u => new
            {
                u.Id,
                u.FullName,
                u.Email,
                u.Phone,
                u.Status,
                u.CreatedAt,
                u.UpdatedAt,
                profile = u.OwnerProfile == null
                    ? null
                    : new
                    {
                        u.OwnerProfile.CitizenId,
                        u.OwnerProfile.Address,
                        u.OwnerProfile.BankName,
                        u.OwnerProfile.BankAccount,
                        u.OwnerProfile.BankAccountName,
                        u.OwnerProfile.CreatedAt
                    },
                hasBankAccount =
                    u.OwnerProfile != null &&
                    u.OwnerProfile.BankName != null &&
                    u.OwnerProfile.BankAccount != null &&
                    u.OwnerProfile.BankAccountName != null,
                homestayCount = u.Homestays.Count(h => !h.IsDeleted),
                approvedHomestayCount = u.Homestays.Count(h =>
                    !h.IsDeleted && h.Status == "approved"
                )
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = owners.Count,
            owners
        });
    }

    // Endpoint cũ vẫn được giữ để Header và các màn hình cũ hoạt động.
    [HttpGet("pending")]
    public async Task<IActionResult> GetPendingOwners()
    {
        var owners = await _context.Users
            .AsNoTracking()
            .Where(u =>
                u.Role == "owner" &&
                u.Status == "pending"
            )
            .OrderBy(u => u.CreatedAt)
            .Select(u => new
            {
                u.Id,
                u.FullName,
                u.Email,
                u.Phone,
                u.Status,
                u.CreatedAt,
                profile = u.OwnerProfile == null
                    ? null
                    : new
                    {
                        u.OwnerProfile.CitizenId,
                        u.OwnerProfile.Address
                    }
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = owners.Count,
            owners
        });
    }

    [HttpGet("{id}")]
    public async Task<IActionResult> GetOwner(uint id)
    {
        var owner = await _context.Users
            .AsNoTracking()
            .Where(u => u.Id == id && u.Role == "owner")
            .Select(u => new
            {
                u.Id,
                u.FullName,
                u.Email,
                u.Phone,
                u.Status,
                u.CreatedAt,
                u.UpdatedAt,
                profile = u.OwnerProfile == null
                    ? null
                    : new
                    {
                        u.OwnerProfile.Id,
                        u.OwnerProfile.CitizenId,
                        u.OwnerProfile.Address,
                        u.OwnerProfile.BankName,
                        u.OwnerProfile.BankAccount,
                        u.OwnerProfile.BankAccountName,
                        u.OwnerProfile.CreatedAt
                    },
                homestays = u.Homestays
                    .Where(h => !h.IsDeleted)
                    .OrderByDescending(h => h.CreatedAt)
                    .Select(h => new
                    {
                        h.Id,
                        h.Name,
                        h.Slug,
                        h.Status,
                        h.Address,
                        h.CreatedAt
                    })
                    .ToList()
            })
            .FirstOrDefaultAsync();

        if (owner == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy tài khoản chủ homestay."
            });
        }

        return Ok(new
        {
            success = true,
            owner
        });
    }

    [HttpPut("{id}/approve")]
    public async Task<IActionResult> ApproveOwner(uint id)
    {
        var owner = await _context.Users
            .FirstOrDefaultAsync(u =>
                u.Id == id &&
                u.Role == "owner"
            );

        if (owner == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy tài khoản chủ homestay."
            });
        }

        if (owner.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Chỉ có thể duyệt tài khoản đang chờ xử lý.",
                currentStatus = owner.Status
            });
        }

        bool hasRequiredProfile = await _context.OwnerProfiles
            .AnyAsync(p =>
                p.UserId == owner.Id &&
                p.CitizenId != "" &&
                p.Address != ""
            );

        if (!hasRequiredProfile)
        {
            return BadRequest(new
            {
                success = false,
                message =
                    "Tài khoản chưa có đủ CCCD/CMND và địa chỉ để duyệt."
            });
        }

        owner.Status = "approved";
        owner.UpdatedAt = DateTime.UtcNow;
        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã duyệt tài khoản chủ homestay.",
            owner = new
            {
                owner.Id,
                owner.FullName,
                owner.Email,
                owner.Phone,
                owner.Status
            }
        });
    }

    [HttpPut("{id}/reject")]
    public async Task<IActionResult> RejectOwner(uint id)
    {
        var owner = await _context.Users
            .FirstOrDefaultAsync(u =>
                u.Id == id &&
                u.Role == "owner"
            );

        if (owner == null)
        {
            return NotFound(new
            {
                success = false,
                message = "Không tìm thấy tài khoản chủ homestay."
            });
        }

        if (owner.Status != "pending")
        {
            return BadRequest(new
            {
                success = false,
                message = "Chỉ có thể từ chối tài khoản đang chờ xử lý.",
                currentStatus = owner.Status
            });
        }

        owner.Status = "rejected";
        owner.UpdatedAt = DateTime.UtcNow;
        await _context.SaveChangesAsync();

        return Ok(new
        {
            success = true,
            message = "Đã từ chối tài khoản chủ homestay.",
            owner = new
            {
                owner.Id,
                owner.FullName,
                owner.Email,
                owner.Phone,
                owner.Status
            }
        });
    }
}

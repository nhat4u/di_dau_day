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
    private readonly DiDauDayDbContext _context;

    public AdminOwnersController(DiDauDayDbContext context)
    {
        _context = context;
    }

    // Danh sách chủ homestay đang chờ duyệt
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
                u.Role,
                u.Status,
                u.CreatedAt
            })
            .ToListAsync();

        return Ok(new
        {
            success = true,
            total = owners.Count,
            owners
        });
    }

    // Duyệt chủ homestay
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

        if (owner.Status == "approved")
        {
            return BadRequest(new
            {
                success = false,
                message = "Tài khoản này đã được duyệt."
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
                owner.Role,
                owner.Status
            }
        });
    }

    // Từ chối chủ homestay
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
                owner.Role,
                owner.Status
            }
        });
    }
}
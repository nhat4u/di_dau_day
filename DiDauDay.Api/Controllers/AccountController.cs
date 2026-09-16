using System.Security.Claims;
using Microsoft.AspNetCore.Authorization;
using Microsoft.AspNetCore.Mvc;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/[controller]")]
public class AccountController : ControllerBase
{
    [Authorize]
    [HttpGet("me")]
    public IActionResult GetCurrentUser()
    {
        return Ok(new
        {
            success = true,
            message = "JWT hợp lệ.",
            user = new
            {
                id = User.FindFirstValue(ClaimTypes.NameIdentifier),
                fullName = User.FindFirstValue(ClaimTypes.Name),
                email = User.FindFirstValue(ClaimTypes.Email),
                role = User.FindFirstValue(ClaimTypes.Role)
            }
        });
    }

    [Authorize(Roles = "admin")]
    [HttpGet("admin-only")]
    public IActionResult AdminOnly()
    {
        return Ok(new
        {
            success = true,
            message = "Bạn có quyền quản trị viên."
        });
    }

    [Authorize(Roles = "owner")]
    [HttpGet("owner-only")]
    public IActionResult OwnerOnly()
    {
        return Ok(new
        {
            success = true,
            message = "Bạn có quyền chủ homestay."
        });
    }

    [Authorize(Roles = "guest")]
    [HttpGet("guest-only")]
    public IActionResult GuestOnly()
    {
        return Ok(new
        {
            success = true,
            message = "Bạn có quyền khách hàng."
        });
    }
}
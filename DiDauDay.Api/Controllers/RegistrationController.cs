using System.ComponentModel.DataAnnotations;
using System.Text.RegularExpressions;
using DiDauDay.Api.Data;
using DiDauDay.Api.Models;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/auth")]
public class RegistrationController : ControllerBase
{
    private readonly DiDauDayDbContext _context;

    public RegistrationController(DiDauDayDbContext context)
    {
        _context = context;
    }

    [HttpPost("register")]
    public async Task<IActionResult> Register(RegisterRequest request)
    {
        string fullName = request.FullName.Trim();
        string email = request.Email.Trim().ToLowerInvariant();
        string phone = request.Phone.Trim();
        string role = request.Role.Trim().ToLowerInvariant();

        if (role != "guest" && role != "owner")
        {
            return BadRequest(new
            {
                success = false,
                message = "Vai trò chỉ được phép là guest hoặc owner."
            });
        }

        if (request.Password != request.ConfirmPassword)
        {
            return BadRequest(new
            {
                success = false,
                message = "Mật khẩu xác nhận không khớp."
            });
        }

        if (!Regex.IsMatch(phone, @"^0[0-9]{9}$"))
        {
            return BadRequest(new
            {
                success = false,
                message = "Số điện thoại phải gồm 10 số và bắt đầu bằng số 0."
            });
        }

        bool emailExists = await _context.Users
            .AnyAsync(u => u.Email == email);

        if (emailExists)
        {
            return Conflict(new
            {
                success = false,
                message = "Email đã được sử dụng."
            });
        }

        bool phoneExists = await _context.Users
            .AnyAsync(u => u.Phone == phone);

        if (phoneExists)
        {
            return Conflict(new
            {
                success = false,
                message = "Số điện thoại đã được sử dụng."
            });
        }

        string status = role == "owner"
            ? "pending"
            : "approved";

        var user = new User
        {
            FullName = fullName,
            Email = email,
            Phone = phone,
            Password = BCrypt.Net.BCrypt.HashPassword(request.Password),
            Role = role,
            Status = status,
            CreatedAt = DateTime.UtcNow,
            UpdatedAt = DateTime.UtcNow
        };

        _context.Users.Add(user);
        await _context.SaveChangesAsync();

        return StatusCode(StatusCodes.Status201Created, new
        {
            success = true,
            message = role == "owner"
                ? "Đăng ký thành công. Tài khoản chủ homestay đang chờ duyệt."
                : "Đăng ký tài khoản thành công.",
            user = new
            {
                user.Id,
                user.FullName,
                user.Email,
                user.Phone,
                user.Role,
                user.Status
            }
        });
    }
}

public sealed class RegisterRequest
{
    [Required(ErrorMessage = "Vui lòng nhập họ tên.")]
    [StringLength(
        100,
        MinimumLength = 2,
        ErrorMessage = "Họ tên phải có từ 2 đến 100 ký tự."
    )]
    public string FullName { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập email.")]
    [EmailAddress(ErrorMessage = "Email không hợp lệ.")]
    public string Email { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập số điện thoại.")]
    public string Phone { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập mật khẩu.")]
    [MinLength(8, ErrorMessage = "Mật khẩu phải có ít nhất 8 ký tự.")]
    public string Password { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng xác nhận mật khẩu.")]
    public string ConfirmPassword { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng chọn vai trò.")]
    public string Role { get; set; } = "guest";
}
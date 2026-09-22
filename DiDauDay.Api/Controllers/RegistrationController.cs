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
        string? citizenId = request.CitizenId?.Trim();
        string? address = request.Address?.Trim();

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

        if (role == "owner")
        {
            if (
                string.IsNullOrWhiteSpace(citizenId) ||
                !Regex.IsMatch(citizenId, @"^\d{9,12}$")
            )
            {
                return BadRequest(new
                {
                    success = false,
                    message =
                        "CCCD/CMND phải gồm từ 9 đến 12 chữ số."
                });
            }

            if (
                string.IsNullOrWhiteSpace(address) ||
                address.Length < 5 ||
                address.Length > 255
            )
            {
                return BadRequest(new
                {
                    success = false,
                    message =
                        "Địa chỉ phải có từ 5 đến 255 ký tự."
                });
            }

            bool citizenIdExists = await _context.OwnerProfiles
                .AnyAsync(p => p.CitizenId == citizenId);

            if (citizenIdExists)
            {
                return Conflict(new
                {
                    success = false,
                    message = "Số CCCD/CMND đã được sử dụng."
                });
            }
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

        await using var transaction =
            await _context.Database.BeginTransactionAsync();

        try
        {
            _context.Users.Add(user);
            await _context.SaveChangesAsync();

            if (role == "owner")
            {
                var ownerProfile = new OwnerProfile
                {
                    UserId = user.Id,
                    CitizenId = citizenId!,
                    Address = address!,
                    BankName = null,
                    BankAccount = null,
                    BankAccountName = null,
                    CreatedAt = DateTime.UtcNow
                };

                _context.OwnerProfiles.Add(ownerProfile);
                await _context.SaveChangesAsync();
            }

            await transaction.CommitAsync();
        }
        catch
        {
            await transaction.RollbackAsync();
            throw;
        }

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
                user.Status,
                ownerProfile = role == "owner"
                    ? new
                    {
                        CitizenId = citizenId,
                        Address = address,
                        HasBankAccount = false
                    }
                    : null
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

    public string? CitizenId { get; set; }

    public string? Address { get; set; }
}

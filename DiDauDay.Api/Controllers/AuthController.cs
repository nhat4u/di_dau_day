using System.ComponentModel.DataAnnotations;
using System.IdentityModel.Tokens.Jwt;
using System.Security.Claims;
using System.Text;
using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;
using Microsoft.IdentityModel.Tokens;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/[controller]")]
public class AuthController : ControllerBase
{
    private readonly DiDauDayDbContext _context;
    private readonly IConfiguration _configuration;

    public AuthController(
        DiDauDayDbContext context,
        IConfiguration configuration
    )
    {
        _context = context;
        _configuration = configuration;
    }

    [HttpPost("login")]
    public async Task<IActionResult> Login(LoginRequest request)
    {
        string email = request.Email.Trim();

        var user = await _context.Users
            .AsNoTracking()
            .FirstOrDefaultAsync(u => u.Email == email);

        if (
            user == null ||
            !BCrypt.Net.BCrypt.Verify(request.Password, user.Password)
        )
        {
            return Unauthorized(new
            {
                success = false,
                message = "Email hoặc mật khẩu không đúng."
            });
        }

        if (user.Status != "approved")
        {
            return StatusCode(StatusCodes.Status403Forbidden, new
            {
                success = false,
                message = user.Status switch
                {
                    "pending" => "Tài khoản đang chờ quản trị viên duyệt.",
                    "rejected" => "Tài khoản đã bị từ chối.",
                    "blocked" => "Tài khoản đã bị khóa.",
                    _ => "Tài khoản chưa được phép đăng nhập."
                }
            });
        }

        int expiresMinutes = int.TryParse(
            _configuration["Jwt:ExpiresMinutes"],
            out int minutes
        ) ? minutes : 120;

        var claims = new List<Claim>
        {
            new(JwtRegisteredClaimNames.Sub, user.Id.ToString()),
            new(ClaimTypes.NameIdentifier, user.Id.ToString()),
            new(ClaimTypes.Name, user.FullName),
            new(ClaimTypes.Email, user.Email),
            new(ClaimTypes.Role, user.Role)
        };

        var securityKey = new SymmetricSecurityKey(
            Encoding.UTF8.GetBytes(_configuration["Jwt:Key"]!)
        );

        var credentials = new SigningCredentials(
            securityKey,
            SecurityAlgorithms.HmacSha256
        );

        DateTime expiresAt = DateTime.UtcNow.AddMinutes(expiresMinutes);

        var jwtToken = new JwtSecurityToken(
            issuer: _configuration["Jwt:Issuer"],
            audience: _configuration["Jwt:Audience"],
            claims: claims,
            expires: expiresAt,
            signingCredentials: credentials
        );

        string accessToken =
            new JwtSecurityTokenHandler().WriteToken(jwtToken);

        return Ok(new
        {
            success = true,
            message = "Đăng nhập thành công.",
            accessToken,
            tokenType = "Bearer",
            expiresAt,
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

public sealed class LoginRequest
{
    [Required(ErrorMessage = "Vui lòng nhập email.")]
    [EmailAddress(ErrorMessage = "Email không hợp lệ.")]
    public string Email { get; set; } = string.Empty;

    [Required(ErrorMessage = "Vui lòng nhập mật khẩu.")]
    public string Password { get; set; } = string.Empty;
}
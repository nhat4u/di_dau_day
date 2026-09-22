using DiDauDay.Api.Data;
using Microsoft.AspNetCore.Mvc;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Controllers;

[ApiController]
[Route("api/database")]
public class DatabaseController : ControllerBase
{
    private readonly DiDauDayDbContext _context;

    public DatabaseController(DiDauDayDbContext context)
    {
        _context = context;
    }

    [HttpGet("test")]
    public async Task<IActionResult> TestConnection()
    {
        bool connected = await _context.Database.CanConnectAsync();

        return Ok(new
        {
            success = connected,
            message = connected
                ? "Kết nối MySQL thành công."
                : "Không thể kết nối MySQL."
        });
    }
}

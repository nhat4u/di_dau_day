using System;
using System.Collections.Generic;
using DiDauDay.Api.Models;
using Microsoft.EntityFrameworkCore;

namespace DiDauDay.Api.Data;

public partial class DiDauDayDbContext : DbContext
{
    public DiDauDayDbContext(DbContextOptions<DiDauDayDbContext> options)
        : base(options)
    {
    }

    public virtual DbSet<Booking> Bookings { get; set; }

    public virtual DbSet<Homestay> Homestays { get; set; }

    public virtual DbSet<HomestayImage> HomestayImages { get; set; }

    public virtual DbSet<HomestayPrice> HomestayPrices { get; set; }

    public virtual DbSet<OwnerProfile> OwnerProfiles { get; set; }

    public virtual DbSet<Payment> Payments { get; set; }

    public virtual DbSet<ProfileChangeRequest> ProfileChangeRequests { get; set; }

    public virtual DbSet<RefundRequest> RefundRequests { get; set; }

    public virtual DbSet<Settlement> Settlements { get; set; }

    public virtual DbSet<User> Users { get; set; }

    public virtual DbSet<Wallet> Wallets { get; set; }

    public virtual DbSet<WalletTransaction> WalletTransactions { get; set; }

    public virtual DbSet<WithdrawalRequest> WithdrawalRequests { get; set; }

    protected override void OnModelCreating(ModelBuilder modelBuilder)
    {
        modelBuilder.Entity<Booking>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("bookings");

            entity.HasIndex(e => e.BookingCode, "booking_code").IsUnique();

            entity.HasIndex(e => e.GuestId, "fk_booking_guest");

            entity.HasIndex(e => e.HomestayId, "fk_booking_homestay");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.BookingCode)
                .HasMaxLength(30)
                .HasColumnName("booking_code");
            entity.Property(e => e.BookingType)
                .HasColumnType("enum('hourly','overnight','daytime','day_night')")
                .HasColumnName("booking_type");
            entity.Property(e => e.CheckIn)
                .HasColumnType("datetime")
                .HasColumnName("check_in");
            entity.Property(e => e.CheckOut)
                .HasColumnType("datetime")
                .HasColumnName("check_out");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.GuestCount)
                .HasColumnType("tinyint(3) unsigned")
                .HasColumnName("guest_count");
            entity.Property(e => e.GuestId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("guest_id");
            entity.Property(e => e.HomestayId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("homestay_id");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''pending_payment'''")
                .HasColumnType("enum('pending_payment','funds_held','confirmed','completed','cancelled','refunded','disputed')")
                .HasColumnName("status");
            entity.Property(e => e.TotalAmount)
                .HasPrecision(12)
                .HasColumnName("total_amount");
            entity.Property(e => e.UpdatedAt)
                .ValueGeneratedOnAddOrUpdate()
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("updated_at");

            entity.HasOne(d => d.Guest).WithMany(p => p.Bookings)
                .HasForeignKey(d => d.GuestId)
                .HasConstraintName("fk_booking_guest");

            entity.HasOne(d => d.Homestay).WithMany(p => p.Bookings)
                .HasForeignKey(d => d.HomestayId)
                .HasConstraintName("fk_booking_homestay");
        });

        modelBuilder.Entity<Homestay>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("homestays");

            entity.HasIndex(e => e.OwnerId, "fk_homestay_owner");

            entity.HasIndex(e => e.Slug, "slug").IsUnique();

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.Address)
                .HasMaxLength(255)
                .HasColumnName("address");
            entity.Property(e => e.AutoCheckin)
                .IsRequired()
                .HasDefaultValueSql("'1'")
                .HasColumnName("auto_checkin");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.Description)
                .HasColumnType("text")
                .HasColumnName("description");
            entity.Property(e => e.HasBalcony).HasColumnName("has_balcony");
            entity.Property(e => e.HasBathtub).HasColumnName("has_bathtub");
            entity.Property(e => e.HasMiniPool).HasColumnName("has_mini_pool");
            entity.Property(e => e.IsDeleted).HasColumnName("is_deleted");
            entity.Property(e => e.MaxGuests)
                .HasDefaultValueSql("'4'")
                .HasColumnType("tinyint(3) unsigned")
                .HasColumnName("max_guests");
            entity.Property(e => e.MinimumHours)
                .HasDefaultValueSql("'2'")
                .HasColumnType("tinyint(3) unsigned")
                .HasColumnName("minimum_hours");
            entity.Property(e => e.Name)
                .HasMaxLength(150)
                .HasColumnName("name");
            entity.Property(e => e.OvernightPrice)
                .HasPrecision(12)
                .HasColumnName("overnight_price");
            entity.Property(e => e.OwnerId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("owner_id");
            entity.Property(e => e.PricePerHour)
                .HasPrecision(12)
                .HasColumnName("price_per_hour");
            entity.Property(e => e.Province)
                .HasMaxLength(100)
                .HasColumnName("province");
            entity.Property(e => e.RejectionReason)
                .HasMaxLength(255)
                .HasDefaultValueSql("'NULL'")
                .HasColumnName("rejection_reason");
            entity.Property(e => e.RoomRank)
                .HasDefaultValueSql("'''standard'''")
                .HasColumnType("enum('standard','deluxe','premium')")
                .HasColumnName("room_rank");
            entity.Property(e => e.Slug)
                .HasMaxLength(180)
                .HasColumnName("slug");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''approved'''")
                .HasColumnType("enum('draft','pending','approved','rejected','maintenance')")
                .HasColumnName("status");
            entity.Property(e => e.TouristDestination)
                .HasMaxLength(150)
                .HasColumnName("tourist_destination");
            entity.Property(e => e.UpdatedAt)
                .ValueGeneratedOnAddOrUpdate()
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("updated_at");

            entity.HasOne(d => d.Owner).WithMany(p => p.Homestays)
                .HasForeignKey(d => d.OwnerId)
                .HasConstraintName("fk_homestay_owner");
        });

        modelBuilder.Entity<HomestayImage>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("homestay_images");

            entity.HasIndex(e => e.HomestayId, "fk_image_homestay");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.HomestayId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("homestay_id");
            entity.Property(e => e.ImagePath)
                .HasMaxLength(255)
                .HasColumnName("image_path");
            entity.Property(e => e.IsCover).HasColumnName("is_cover");
            entity.Property(e => e.SortOrder)
                .HasColumnType("int(11)")
                .HasColumnName("sort_order");

            entity.HasOne(d => d.Homestay).WithMany(p => p.HomestayImages)
                .HasForeignKey(d => d.HomestayId)
                .HasConstraintName("fk_image_homestay");
        });

        modelBuilder.Entity<HomestayPrice>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("homestay_prices");

            entity.HasIndex(e => e.HomestayId, "homestay_id").IsUnique();

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.HomestayId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("homestay_id");
            entity.Property(e => e.PriceCombo4Hours)
                .HasPrecision(12)
                .HasColumnName("price_combo_4_hours");
            entity.Property(e => e.PriceDayNightWeekday)
                .HasPrecision(12)
                .HasColumnName("price_day_night_weekday");
            entity.Property(e => e.PriceDayNightWeekend)
                .HasPrecision(12)
                .HasColumnName("price_day_night_weekend");
            entity.Property(e => e.PriceDayWeekday)
                .HasPrecision(12)
                .HasColumnName("price_day_weekday");
            entity.Property(e => e.PriceDayWeekend)
                .HasPrecision(12)
                .HasColumnName("price_day_weekend");
            entity.Property(e => e.PriceExtraHour)
                .HasPrecision(12)
                .HasColumnName("price_extra_hour");
            entity.Property(e => e.PriceFirst2Hours)
                .HasPrecision(12)
                .HasColumnName("price_first_2_hours");
            entity.Property(e => e.PriceOvernightWeekday)
                .HasPrecision(12)
                .HasColumnName("price_overnight_weekday");
            entity.Property(e => e.PriceOvernightWeekend)
                .HasPrecision(12)
                .HasColumnName("price_overnight_weekend");
            entity.Property(e => e.UpdatedAt)
                .ValueGeneratedOnAddOrUpdate()
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("updated_at");

            entity.HasOne(d => d.Homestay).WithOne(p => p.HomestayPrice)
                .HasForeignKey<HomestayPrice>(d => d.HomestayId)
                .HasConstraintName("fk_price_homestay");
        });

        modelBuilder.Entity<OwnerProfile>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("owner_profiles");

            entity.HasIndex(e => e.CitizenId, "citizen_id").IsUnique();

            entity.HasIndex(e => e.UserId, "user_id").IsUnique();

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.Address)
                .HasMaxLength(255)
                .HasColumnName("address");
            entity.Property(e => e.BankAccount)
                .HasMaxLength(50)
                .HasColumnName("bank_account");
            entity.Property(e => e.BankAccountName)
                .HasMaxLength(100)
                .HasColumnName("bank_account_name");
            entity.Property(e => e.BankName)
                .HasMaxLength(100)
                .HasColumnName("bank_name");
            entity.Property(e => e.CitizenId)
                .HasMaxLength(20)
                .HasColumnName("citizen_id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.UserId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("user_id");

            entity.HasOne(d => d.User).WithOne(p => p.OwnerProfile)
                .HasForeignKey<OwnerProfile>(d => d.UserId)
                .HasConstraintName("fk_owner_user");
        });

        modelBuilder.Entity<Payment>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("payments");

            entity.HasIndex(e => e.BookingId, "booking_id").IsUnique();

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.Amount)
                .HasPrecision(12)
                .HasColumnName("amount");
            entity.Property(e => e.BookingId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("booking_id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.PaidAt)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("datetime")
                .HasColumnName("paid_at");
            entity.Property(e => e.PaymentMethod)
                .HasColumnType("enum('bank_transfer','momo','vnpay')")
                .HasColumnName("payment_method");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''pending'''")
                .HasColumnType("enum('pending','held','refunded','settled','failed')")
                .HasColumnName("status");
            entity.Property(e => e.TransactionCode)
                .HasMaxLength(100)
                .HasDefaultValueSql("'NULL'")
                .HasColumnName("transaction_code");

            entity.HasOne(d => d.Booking).WithOne(p => p.Payment)
                .HasForeignKey<Payment>(d => d.BookingId)
                .HasConstraintName("fk_payment_booking");
        });

        modelBuilder.Entity<ProfileChangeRequest>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("profile_change_requests");

            entity.HasIndex(e => e.ProcessedBy, "fk_profile_request_admin");

            entity.HasIndex(e => e.OwnerId, "fk_profile_request_owner");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.AdminNote)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("text")
                .HasColumnName("admin_note");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.OwnerId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("owner_id");
            entity.Property(e => e.ProcessedAt)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("datetime")
                .HasColumnName("processed_at");
            entity.Property(e => e.ProcessedBy)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("int(10) unsigned")
                .HasColumnName("processed_by");
            entity.Property(e => e.Reason)
                .HasColumnType("text")
                .HasColumnName("reason");
            entity.Property(e => e.RequestedInformation)
                .HasColumnType("text")
                .HasColumnName("requested_information");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''pending'''")
                .HasColumnType("enum('pending','approved','rejected','completed')")
                .HasColumnName("status");

            entity.HasOne(d => d.Owner).WithMany(p => p.ProfileChangeRequestOwners)
                .HasForeignKey(d => d.OwnerId)
                .HasConstraintName("fk_profile_request_owner");

            entity.HasOne(d => d.ProcessedByNavigation).WithMany(p => p.ProfileChangeRequestProcessedByNavigations)
                .HasForeignKey(d => d.ProcessedBy)
                .OnDelete(DeleteBehavior.SetNull)
                .HasConstraintName("fk_profile_request_admin");
        });

        modelBuilder.Entity<RefundRequest>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("refund_requests");

            entity.HasIndex(e => e.ResolvedBy, "fk_refund_admin");

            entity.HasIndex(e => e.BookingId, "fk_refund_booking");

            entity.HasIndex(e => e.RequestedBy, "fk_refund_requester");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.AdminNote)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("text")
                .HasColumnName("admin_note");
            entity.Property(e => e.BookingId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("booking_id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.Description)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("text")
                .HasColumnName("description");
            entity.Property(e => e.Reason)
                .HasColumnType("enum('guest_cancelled','host_cancelled','power_outage','service_issue','other')")
                .HasColumnName("reason");
            entity.Property(e => e.RefundAmount)
                .HasPrecision(12)
                .HasColumnName("refund_amount");
            entity.Property(e => e.RequestedBy)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("requested_by");
            entity.Property(e => e.ResolvedAt)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("datetime")
                .HasColumnName("resolved_at");
            entity.Property(e => e.ResolvedBy)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("int(10) unsigned")
                .HasColumnName("resolved_by");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''pending'''")
                .HasColumnType("enum('pending','approved','rejected','completed')")
                .HasColumnName("status");

            entity.HasOne(d => d.Booking).WithMany(p => p.RefundRequests)
                .HasForeignKey(d => d.BookingId)
                .HasConstraintName("fk_refund_booking");

            entity.HasOne(d => d.RequestedByNavigation).WithMany(p => p.RefundRequestRequestedByNavigations)
                .HasForeignKey(d => d.RequestedBy)
                .OnDelete(DeleteBehavior.Restrict)
                .HasConstraintName("fk_refund_requester");

            entity.HasOne(d => d.ResolvedByNavigation).WithMany(p => p.RefundRequestResolvedByNavigations)
                .HasForeignKey(d => d.ResolvedBy)
                .OnDelete(DeleteBehavior.Restrict)
                .HasConstraintName("fk_refund_admin");
        });

        modelBuilder.Entity<Settlement>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("settlements");

            entity.HasIndex(e => e.BookingId, "booking_id").IsUnique();

            entity.HasIndex(e => e.OwnerId, "fk_settlement_owner");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.BookingId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("booking_id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.GrossAmount)
                .HasPrecision(12)
                .HasColumnName("gross_amount");
            entity.Property(e => e.OwnerAmount)
                .HasPrecision(12)
                .HasColumnName("owner_amount");
            entity.Property(e => e.OwnerId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("owner_id");
            entity.Property(e => e.PlatformFee)
                .HasPrecision(12)
                .HasColumnName("platform_fee");
            entity.Property(e => e.SettledAt)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("datetime")
                .HasColumnName("settled_at");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''pending'''")
                .HasColumnType("enum('pending','held','processing','completed','failed')")
                .HasColumnName("status");

            entity.HasOne(d => d.Booking).WithOne(p => p.Settlement)
                .HasForeignKey<Settlement>(d => d.BookingId)
                .HasConstraintName("fk_settlement_booking");

            entity.HasOne(d => d.Owner).WithMany(p => p.Settlements)
                .HasForeignKey(d => d.OwnerId)
                .OnDelete(DeleteBehavior.Restrict)
                .HasConstraintName("fk_settlement_owner");
        });

        modelBuilder.Entity<User>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("users");

            entity.HasIndex(e => e.Email, "email").IsUnique();

            entity.HasIndex(e => e.Phone, "phone").IsUnique();

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.Email)
                .HasMaxLength(150)
                .HasColumnName("email");
            entity.Property(e => e.FullName)
                .HasMaxLength(100)
                .HasColumnName("full_name");
            entity.Property(e => e.Password)
                .HasMaxLength(255)
                .HasColumnName("password");
            entity.Property(e => e.Phone)
                .HasMaxLength(15)
                .HasColumnName("phone");
            entity.Property(e => e.Role)
                .HasDefaultValueSql("'''guest'''")
                .HasColumnType("enum('admin','owner','guest')")
                .HasColumnName("role");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''approved'''")
                .HasColumnType("enum('pending','approved','rejected','blocked')")
                .HasColumnName("status");
            entity.Property(e => e.UpdatedAt)
                .ValueGeneratedOnAddOrUpdate()
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("updated_at");
        });

        modelBuilder.Entity<Wallet>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("wallets");

            entity.HasIndex(e => e.UserId, "user_id").IsUnique();

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.AvailableBalance)
                .HasPrecision(12)
                .HasColumnName("available_balance");
            entity.Property(e => e.PendingBalance)
                .HasPrecision(12)
                .HasColumnName("pending_balance");
            entity.Property(e => e.TotalEarned)
                .HasPrecision(12)
                .HasColumnName("total_earned");
            entity.Property(e => e.UpdatedAt)
                .ValueGeneratedOnAddOrUpdate()
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("updated_at");
            entity.Property(e => e.UserId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("user_id");

            entity.HasOne(d => d.User).WithOne(p => p.Wallet)
                .HasForeignKey<Wallet>(d => d.UserId)
                .HasConstraintName("fk_wallet_user");
        });

        modelBuilder.Entity<WalletTransaction>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("wallet_transactions");

            entity.HasIndex(e => e.BookingId, "fk_transaction_booking");

            entity.HasIndex(e => e.WalletId, "fk_transaction_wallet");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.Amount)
                .HasPrecision(12)
                .HasColumnName("amount");
            entity.Property(e => e.BalanceAfter)
                .HasPrecision(12)
                .HasColumnName("balance_after");
            entity.Property(e => e.BookingId)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("int(10) unsigned")
                .HasColumnName("booking_id");
            entity.Property(e => e.CreatedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("created_at");
            entity.Property(e => e.Description)
                .HasMaxLength(255)
                .HasDefaultValueSql("'NULL'")
                .HasColumnName("description");
            entity.Property(e => e.Direction)
                .HasColumnType("enum('credit','debit')")
                .HasColumnName("direction");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''completed'''")
                .HasColumnType("enum('pending','completed','failed')")
                .HasColumnName("status");
            entity.Property(e => e.TransactionType)
                .HasColumnType("enum('platform_fee','owner_income','withdrawal','refund','adjustment')")
                .HasColumnName("transaction_type");
            entity.Property(e => e.WalletId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("wallet_id");

            entity.HasOne(d => d.Booking).WithMany(p => p.WalletTransactions)
                .HasForeignKey(d => d.BookingId)
                .OnDelete(DeleteBehavior.SetNull)
                .HasConstraintName("fk_transaction_booking");

            entity.HasOne(d => d.Wallet).WithMany(p => p.WalletTransactions)
                .HasForeignKey(d => d.WalletId)
                .HasConstraintName("fk_transaction_wallet");
        });

        modelBuilder.Entity<WithdrawalRequest>(entity =>
        {
            entity.HasKey(e => e.Id).HasName("PRIMARY");

            entity.ToTable("withdrawal_requests");

            entity.HasIndex(e => e.ProcessedBy, "fk_withdrawal_admin");

            entity.HasIndex(e => e.WalletId, "fk_withdrawal_wallet");

            entity.Property(e => e.Id)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("id");
            entity.Property(e => e.AdminNote)
                .HasMaxLength(255)
                .HasDefaultValueSql("'NULL'")
                .HasColumnName("admin_note");
            entity.Property(e => e.Amount)
                .HasPrecision(12)
                .HasColumnName("amount");
            entity.Property(e => e.BankAccount)
                .HasMaxLength(50)
                .HasColumnName("bank_account");
            entity.Property(e => e.BankAccountName)
                .HasMaxLength(100)
                .HasColumnName("bank_account_name");
            entity.Property(e => e.BankName)
                .HasMaxLength(100)
                .HasColumnName("bank_name");
            entity.Property(e => e.ProcessedAt)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("datetime")
                .HasColumnName("processed_at");
            entity.Property(e => e.ProcessedBy)
                .HasDefaultValueSql("'NULL'")
                .HasColumnType("int(10) unsigned")
                .HasColumnName("processed_by");
            entity.Property(e => e.RequestedAt)
                .HasDefaultValueSql("'current_timestamp()'")
                .HasColumnType("timestamp")
                .HasColumnName("requested_at");
            entity.Property(e => e.Status)
                .HasDefaultValueSql("'''pending'''")
                .HasColumnType("enum('pending','approved','rejected','completed')")
                .HasColumnName("status");
            entity.Property(e => e.WalletId)
                .HasColumnType("int(10) unsigned")
                .HasColumnName("wallet_id");

            entity.HasOne(d => d.ProcessedByNavigation).WithMany(p => p.WithdrawalRequests)
                .HasForeignKey(d => d.ProcessedBy)
                .OnDelete(DeleteBehavior.Restrict)
                .HasConstraintName("fk_withdrawal_admin");

            entity.HasOne(d => d.Wallet).WithMany(p => p.WithdrawalRequests)
                .HasForeignKey(d => d.WalletId)
                .HasConstraintName("fk_withdrawal_wallet");
        });

        OnModelCreatingPartial(modelBuilder);
    }

    partial void OnModelCreatingPartial(ModelBuilder modelBuilder);
}

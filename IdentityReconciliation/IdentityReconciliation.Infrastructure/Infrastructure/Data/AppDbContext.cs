using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Infrastructure.Identity;
using Microsoft.AspNetCore.Identity.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore;

namespace IdentityReconciliation.Infrastructure.Data
{
    public class AppDbContext : IdentityDbContext<ApplicationUser>
    {
        public AppDbContext(DbContextOptions<AppDbContext> options) : base(options)
        {
        }

        public DbSet<UserMap> UserMaps { get; set; }
        public DbSet<IntegrationSystem> IntegrationSystems { get; set; }
        public DbSet<ControlCenterSetting> ControlCenterSettings { get; set; }
        public DbSet<SyncJob> SyncJobs { get; set; }
        public DbSet<SyncOperation> SyncOperations { get; set; }
        public DbSet<SyncAttempt> SyncAttempts { get; set; }
        public DbSet<AuditEvent> AuditEvents { get; set; }

        protected override void OnModelCreating(ModelBuilder modelBuilder)
        {
            base.OnModelCreating(modelBuilder);

            modelBuilder.Entity<UserMap>(entity =>
            {
                entity.HasKey(e => e.Id);

                entity.Property(e => e.Email).HasMaxLength(256);
                entity.Property(e => e.Username).HasMaxLength(256);

                entity.HasIndex(e => e.MoodleId).IsUnique().HasFilter("[MoodleId] IS NOT NULL").HasDatabaseName("UX_UserMaps_MoodleId");
                entity.HasIndex(e => e.GibbonId).IsUnique().HasFilter("[GibbonId] IS NOT NULL").HasDatabaseName("UX_UserMaps_GibbonId");
                entity.HasIndex(e => e.Email).HasDatabaseName("IX_UserMaps_Email");
                entity.HasIndex(e => e.IdentityUserId).IsUnique().HasFilter("[IdentityUserId] IS NOT NULL").HasDatabaseName("UX_UserMaps_IdentityUserId");
            });

            modelBuilder.Entity<IntegrationSystem>(entity =>
            {
                entity.HasKey(x => x.Id);
                entity.Property(x => x.Name).HasMaxLength(150).IsRequired();
                entity.Property(x => x.BaseUrl).HasMaxLength(500).IsRequired();
                entity.HasIndex(x => new { x.Type, x.Name }).IsUnique();
            });

            modelBuilder.Entity<ControlCenterSetting>(entity =>
            {
                entity.HasKey(x => x.Id);
                entity.Property(x => x.Section).HasMaxLength(100).IsRequired();
                entity.Property(x => x.Key).HasMaxLength(150).IsRequired();
                entity.HasIndex(x => new { x.Section, x.Key }).IsUnique();
            });

            modelBuilder.Entity<SyncJob>(entity =>
            {
                entity.HasKey(x => x.Id);
                entity.Property(x => x.JobType).HasMaxLength(100).IsRequired();
                entity.Property(x => x.Direction).HasMaxLength(50).IsRequired();
                entity.Property(x => x.CorrelationId).HasMaxLength(64).IsRequired();
                entity.HasIndex(x => x.CorrelationId).IsUnique();
                entity.HasIndex(x => new { x.Status, x.CreatedAt });
            });

            modelBuilder.Entity<SyncOperation>(entity =>
            {
                entity.HasKey(x => x.Id);
                entity.Property(x => x.IdempotencyKey).HasMaxLength(256).IsRequired();
                entity.HasIndex(x => x.IdempotencyKey).IsUnique();
                entity.HasIndex(x => new { x.Status, x.NextRetryAt });
                entity.HasOne(x => x.SyncJob).WithMany(x => x.Operations).HasForeignKey(x => x.SyncJobId).OnDelete(DeleteBehavior.Cascade);
            });

            modelBuilder.Entity<SyncAttempt>(entity =>
            {
                entity.HasKey(x => x.Id);
                entity.HasIndex(x => new { x.SyncOperationId, x.AttemptNumber }).IsUnique();
                entity.HasOne(x => x.SyncOperation).WithMany(x => x.Attempts).HasForeignKey(x => x.SyncOperationId).OnDelete(DeleteBehavior.Cascade);
            });

            modelBuilder.Entity<AuditEvent>(entity =>
            {
                entity.HasKey(x => x.Id);
                entity.Property(x => x.Category).HasMaxLength(100).IsRequired();
                entity.Property(x => x.Action).HasMaxLength(150).IsRequired();
                entity.HasIndex(x => x.CreatedAt);
                entity.HasIndex(x => x.CorrelationId);
            });
        }
    }
}

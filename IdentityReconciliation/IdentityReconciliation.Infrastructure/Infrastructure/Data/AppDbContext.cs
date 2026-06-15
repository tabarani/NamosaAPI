using IdentityReconciliation.Domain.Entities;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Identity.EntityFrameworkCore;
using Microsoft.EntityFrameworkCore;

namespace IdentityReconciliation.Infrastructure.Data
{
    public class AppDbContext : IdentityDbContext<IdentityUser>
    {
        public AppDbContext(DbContextOptions<AppDbContext> options) : base(options)
        {
        }

        public DbSet<UserMap> UserMaps { get; set; }

        protected override void OnModelCreating(ModelBuilder modelBuilder)
        {
            base.OnModelCreating(modelBuilder);

            modelBuilder.Entity<UserMap>(entity =>
            {
                entity.HasKey(e => e.Id);

                entity.Property(e => e.Email).HasMaxLength(256);
                entity.Property(e => e.Username).HasMaxLength(256);

                entity.HasIndex(e => e.MoodleId).HasDatabaseName("IX_UserMaps_MoodleId");
                entity.HasIndex(e => e.GibbonId).HasDatabaseName("IX_UserMaps_GibbonId");
                entity.HasIndex(e => e.Email).HasDatabaseName("IX_UserMaps_Email");
                entity.HasIndex(e => e.IdentityUserId).HasDatabaseName("IX_UserMaps_IdentityUserId");
            });
        }
    }
}

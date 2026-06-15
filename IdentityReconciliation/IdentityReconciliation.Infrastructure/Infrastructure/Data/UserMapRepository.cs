using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Interfaces;
using Microsoft.EntityFrameworkCore;

namespace IdentityReconciliation.Infrastructure.Data
{
    public class UserMapRepository : IUserMapRepository
    {
        private readonly AppDbContext _context;

        public UserMapRepository(AppDbContext context)
        {
            _context = context;
        }

        public async Task<UserMap?> GetByIdAsync(Guid id)
        {
            return await _context.UserMaps.FindAsync(id);
        }

        public async Task<UserMap?> GetByMoodleIdAsync(int moodleId)
        {
            return await _context.UserMaps
                .FirstOrDefaultAsync(u => u.MoodleId == moodleId);
        }

        public async Task<UserMap?> GetByGibbonIdAsync(int gibbonId)
        {
            return await _context.UserMaps
                .FirstOrDefaultAsync(u => u.GibbonId == gibbonId);
        }

        public async Task<UserMap?> GetByEmailAsync(string email)
        {
            return await _context.UserMaps
                .FirstOrDefaultAsync(u => u.Email != null && u.Email.Equals(email));
        }

        public async Task<UserMap?> GetByIdentityUserIdAsync(Guid identityUserId)
        {
            return await _context.UserMaps
                .FirstOrDefaultAsync(u => u.IdentityUserId == identityUserId);
        }

        public async Task AddAsync(UserMap map)
        {
            await _context.UserMaps.AddAsync(map);
        }

        public Task UpdateAsync(UserMap map)
        {
            _context.UserMaps.Update(map);
            return Task.CompletedTask;
        }

        public async Task SaveChangesAsync()
        {
            await _context.SaveChangesAsync();
        }
    }
}

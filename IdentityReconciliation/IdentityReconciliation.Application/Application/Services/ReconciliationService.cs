using IdentityReconciliation.Application.DTOs;
using IdentityReconciliation.Application.Interfaces;
using IdentityReconciliation.Domain.Entities;
using IdentityReconciliation.Domain.Enums;
using IdentityReconciliation.Domain.Interfaces;
using Microsoft.Extensions.Logging;

namespace IdentityReconciliation.Application.Services
{
    public class ReconciliationService : IReconciliationService
    {
        private readonly IUserMapRepository _userMapRepository;
        private readonly IMoodleApiClient _moodleClient;
        private readonly IGibbonPersonRepository _gibbonRepo;
        private readonly ILogger<ReconciliationService> _logger;

        public ReconciliationService(
            IUserMapRepository userMapRepository,
            IMoodleApiClient moodleClient,
            IGibbonPersonRepository gibbonRepo,
            ILogger<ReconciliationService> logger)
        {
            _userMapRepository = userMapRepository;
            _moodleClient = moodleClient;
            _gibbonRepo = gibbonRepo;
            _logger = logger;
        }

        public async Task<ReconciliationReport> ReconcileAsync()
        {
            _logger.LogInformation("Starting identity reconciliation process");

            var moodleUsers = await _moodleClient.GetAllUsersAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();

            var processedGibbonIds = new HashSet<int>();
            var conflicts = new List<ConflictDetail>();
            int moodleOnlyCount = 0;

            foreach (var moodleUser in moodleUsers)
            {
                _logger.LogDebug("Processing Moodle user {MoodleId} ({Email})", moodleUser.Id, moodleUser.Email);

                List<GibbonPersonDto> emailMatches = new();
                List<GibbonPersonDto> usernameMatches = new();

                if (!string.IsNullOrWhiteSpace(moodleUser.Email))
                {
                    emailMatches = gibbonPersons
                        .Where(g => !string.IsNullOrWhiteSpace(g.Email) &&
                                    g.Email.Equals(moodleUser.Email, StringComparison.OrdinalIgnoreCase))
                        .ToList();
                }

                if (!string.IsNullOrWhiteSpace(moodleUser.Username))
                {
                    usernameMatches = gibbonPersons
                        .Where(g => !string.IsNullOrWhiteSpace(g.Username) &&
                                    g.Username.Equals(moodleUser.Username, StringComparison.OrdinalIgnoreCase))
                        .ToList();
                }

                if (emailMatches.Count == 1)
                {
                    var matchedGibbon = emailMatches[0];
                    processedGibbonIds.Add(matchedGibbon.GibbonPersonId);

                    var userMap = new UserMap
                    {
                        MoodleId = moodleUser.Id,
                        GibbonId = matchedGibbon.GibbonPersonId,
                        Email = moodleUser.Email,
                        Username = moodleUser.Username
                    };

                    userMap.MarkAsLinked(100);
                    await _userMapRepository.AddAsync(userMap);

                    _logger.LogInformation("Linked Moodle user {MoodleId} to Gibbon person {GibbonId} with 100% confidence",
                        moodleUser.Id, matchedGibbon.GibbonPersonId);
                }
                else if (emailMatches.Count > 1)
                {
                    foreach (var matchedGibbon in emailMatches)
                    {
                        var userMap = new UserMap
                        {
                            MoodleId = moodleUser.Id,
                            GibbonId = matchedGibbon.GibbonPersonId,
                            Email = moodleUser.Email,
                            Username = moodleUser.Username
                        };

                        userMap.MarkAsConflict();
                        await _userMapRepository.AddAsync(userMap);
                    }

                    conflicts.Add(new ConflictDetail(
                        moodleUser.Email,
                        new List<MoodleUserDto> { moodleUser },
                        emailMatches));

                    _logger.LogWarning("Conflict detected for Moodle user {MoodleId} with {Count} Gibbon matches",
                        moodleUser.Id, emailMatches.Count);
                }
                else if (usernameMatches.Count == 1 && string.IsNullOrWhiteSpace(moodleUser.Email))
                {
                    var matchedGibbon = usernameMatches[0];
                    processedGibbonIds.Add(matchedGibbon.GibbonPersonId);

                    var userMap = new UserMap
                    {
                        MoodleId = moodleUser.Id,
                        GibbonId = matchedGibbon.GibbonPersonId,
                        Email = moodleUser.Email,
                        Username = moodleUser.Username
                    };

                    userMap.MarkAsLinked(90);
                    await _userMapRepository.AddAsync(userMap);

                    _logger.LogInformation("Linked Moodle user {MoodleId} to Gibbon person {GibbonId} via username with 90% confidence",
                        moodleUser.Id, matchedGibbon.GibbonPersonId);
                }
                else if (usernameMatches.Count > 1 && string.IsNullOrWhiteSpace(moodleUser.Email))
                {
                    foreach (var matchedGibbon in usernameMatches)
                    {
                        var userMap = new UserMap
                        {
                            MoodleId = moodleUser.Id,
                            GibbonId = matchedGibbon.GibbonPersonId,
                            Email = moodleUser.Email,
                            Username = moodleUser.Username
                        };

                        userMap.MarkAsConflict();
                        await _userMapRepository.AddAsync(userMap);
                    }

                    conflicts.Add(new ConflictDetail(
                        moodleUser.Email,
                        new List<MoodleUserDto> { moodleUser },
                        usernameMatches));

                    _logger.LogWarning("Username conflict detected for Moodle user {MoodleId} with {Count} Gibbon matches",
                        moodleUser.Id, usernameMatches.Count);
                }
                else
                {
                    var userMap = new UserMap
                    {
                        MoodleId = moodleUser.Id,
                        Email = moodleUser.Email,
                        Username = moodleUser.Username
                    };

                    await _userMapRepository.AddAsync(userMap);
                    moodleOnlyCount++;

                    _logger.LogDebug("No match found for Moodle user {MoodleId}", moodleUser.Id);
                }
            }

            int gibbonOnlyCount = gibbonPersons.Count(p => !processedGibbonIds.Contains(p.GibbonPersonId));

            await _userMapRepository.SaveChangesAsync();

            var linkedCount = moodleUsers.Count - moodleOnlyCount - conflicts.Sum(c => c.MoodleCandidates.Count);

            var report = new ReconciliationReport(
                LinkedCount: linkedCount,
                ConflictCount: conflicts.Count,
                MoodleOnly: moodleOnlyCount,
                GibbonOnly: gibbonOnlyCount,
                Conflicts: conflicts);

            _logger.LogInformation("Reconciliation complete. Linked: {Linked}, Conflicts: {Conflicts}, MoodleOnly: {MoodleOnly}, GibbonOnly: {GibbonOnly}",
                report.LinkedCount, report.ConflictCount, report.MoodleOnly, report.GibbonOnly);

            return report;
        }

        public async Task<DashboardStatsDto> GetDashboardStatsAsync()
        {
            var linkedCount = await _userMapRepository.CountByStatusAsync(MatchStatus.Linked);
            var pendingCount = await _userMapRepository.CountByStatusAsync(MatchStatus.Pending);
            var conflictCount = await _userMapRepository.CountByStatusAsync(MatchStatus.Conflict);
            
            // Unmatched = records with MoodleId but no GibbonId and status Pending
            var allPending = await _userMapRepository.GetPendingMatchesAsync();
            var unmatchedCount = allPending.Count(u => u.GibbonId == null);

            return new DashboardStatsDto(linkedCount, pendingCount, conflictCount, unmatchedCount);
        }

        public async Task<List<PendingMatchDto>> GetPendingMatchesAsync()
        {
            var pendingMaps = await _userMapRepository.GetPendingMatchesAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();
            var moodleUsers = await _moodleClient.GetAllUsersAsync();

            var result = new List<PendingMatchDto>();

            foreach (var map in pendingMaps)
            {
                var gibbonPerson = map.GibbonId.HasValue 
                    ? gibbonPersons.FirstOrDefault(g => g.GibbonPersonId == map.GibbonId.Value)
                    : null;
                
                var moodleUser = map.MoodleId.HasValue 
                    ? moodleUsers.FirstOrDefault(m => m.Id == map.MoodleId.Value)
                    : null;

                int matchScore = CalculateMatchScore(map, gibbonPerson, moodleUser);

                result.Add(new PendingMatchDto(
                    UserMapId: map.Id,
                    GibbonId: map.GibbonId,
                    GibbonName: gibbonPerson?.OfficialName ?? map.Email,
                    GibbonEmail: gibbonPerson?.Email ?? map.Email,
                    MoodleId: map.MoodleId,
                    MoodleName: $"{moodleUser?.Firstname} {moodleUser?.Lastname}".Trim(),
                    MoodleEmail: moodleUser?.Email ?? map.Email,
                    IdPUsername: map.Username,
                    MatchScore: matchScore
                ));
            }

            return result;
        }

        public async Task<List<ConflictDetail>> GetConflictsAsync()
        {
            var conflictMaps = await _userMapRepository.GetConflictsAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();
            var moodleUsers = await _moodleClient.GetAllUsersAsync();

            var conflicts = new List<ConflictDetail>();

            foreach (var map in conflictMaps)
            {
                var moodleUser = map.MoodleId.HasValue 
                    ? moodleUsers.FirstOrDefault(m => m.Id == map.MoodleId.Value)
                    : null;

                if (moodleUser != null)
                {
                    var gibbonCandidates = gibbonPersons
                        .Where(g => g.Email != null && g.Email.Equals(map.Email, StringComparison.OrdinalIgnoreCase))
                        .ToList();

                    if (gibbonCandidates.Any())
                    {
                        conflicts.Add(new ConflictDetail(
                            Email: map.Email,
                            MoodleCandidates: new List<MoodleUserDto> { new MoodleUserDto(moodleUser.Id, moodleUser.Username, moodleUser.Email, moodleUser.Firstname, moodleUser.Lastname, moodleUser.Auth) },
                            GibbonCandidates: gibbonCandidates
                        ));
                    }
                }
            }

            return conflicts;
        }

        public async Task<List<SyncLogDto>> GetSyncLogsAsync()
        {
            var recentLogs = await _userMapRepository.GetRecentSyncLogsAsync(50);
            
            return recentLogs.Select(log => new SyncLogDto(
                Id: log.Id,
                Action: log.Status.ToString(),
                Details: $"MoodleId: {log.MoodleId}, GibbonId: {log.GibbonId}, Email: {log.Email}, Confidence: {log.MatchConfidence}%",
                CreatedAt: log.UpdatedAt
            )).ToList();
        }

        public async Task<int> AutoMatchAsync()
        {
            _logger.LogInformation("Running auto-match algorithm");
            
            var pendingMaps = await _userMapRepository.GetPendingMatchesAsync();
            var gibbonPersons = await _gibbonRepo.GetAllActivePersonsAsync();
            var moodleUsers = await _moodleClient.GetAllUsersAsync();
            
            int autoMatchedCount = 0;

            foreach (var map in pendingMaps.Where(m => m.GibbonId == null && !string.IsNullOrWhiteSpace(m.Email)))
            {
                var exactMatches = gibbonPersons
                    .Where(g => !string.IsNullOrWhiteSpace(g.Email) && 
                               g.Email.Equals(map.Email, StringComparison.OrdinalIgnoreCase))
                    .ToList();

                if (exactMatches.Count == 1)
                {
                    map.GibbonId = exactMatches[0].GibbonPersonId;
                    map.MarkAsLinked(100);
                    await _userMapRepository.UpdateAsync(map);
                    autoMatchedCount++;

                    _logger.LogInformation("Auto-matched UserMap {Id} to GibbonPerson {GibbonId}", map.Id, map.GibbonId);
                }
            }

            await _userMapRepository.SaveChangesAsync();
            _logger.LogInformation("Auto-match complete. {Count} records matched", autoMatchedCount);

            return autoMatchedCount;
        }

        public async Task<bool> ResolveConflictAsync(Guid userMapId, bool link)
        {
            var userMap = await _userMapRepository.GetByIdAsync(userMapId);
            if (userMap == null) return false;

            if (link)
            {
                userMap.MarkAsLinked(userMap.MatchConfidence > 0 ? userMap.MatchConfidence : 80);
            }
            else
            {
                // Mark as ignored by setting a special status or just leave as pending with note
                // For simplicity, we'll mark it as linked with low confidence if they explicitly confirm
                // or we could add an Ignored status - for now just don't link
                return false;
            }

            await _userMapRepository.UpdateAsync(userMap);
            await _userMapRepository.SaveChangesAsync();
            
            return true;
        }

        public async Task<bool> LinkUsersAsync(int? moodleId, int? gibbonId, string? email)
        {
            if (!moodleId.HasValue || !gibbonId.HasValue) return false;

            UserMap? userMap = null;
            
            if (!string.IsNullOrWhiteSpace(email))
            {
                userMap = await _userMapRepository.GetByEmailAsync(email);
            }

            if (userMap == null && moodleId.HasValue)
            {
                userMap = await _userMapRepository.GetByMoodleIdAsync(moodleId.Value);
            }

            if (userMap == null)
            {
                userMap = new UserMap
                {
                    MoodleId = moodleId.Value,
                    GibbonId = gibbonId.Value,
                    Email = email
                };
                await _userMapRepository.AddAsync(userMap);
            }
            else
            {
                userMap.GibbonId = gibbonId.Value;
                if (!string.IsNullOrWhiteSpace(email))
                {
                    userMap.Email = email;
                }
                await _userMapRepository.UpdateAsync(userMap);
            }

            userMap.MarkAsLinked(100);
            await _userMapRepository.SaveChangesAsync();

            return true;
        }

        public async Task IgnoreMatchAsync(Guid userMapId)
        {
            var userMap = await _userMapRepository.GetByIdAsync(userMapId);
            if (userMap != null)
            {
                // For now, we'll just leave it as pending but you could add an Ignored status
                // In a real implementation, you might want to add this to the MatchStatus enum
                await _userMapRepository.SaveChangesAsync();
            }
        }

        private int CalculateMatchScore(UserMap map, GibbonPersonDto? gibbonPerson, MoodleUserDto? moodleUser)
        {
            int score = 0;

            // Email match gives highest score
            if (!string.IsNullOrWhiteSpace(map.Email) && 
                !string.IsNullOrWhiteSpace(gibbonPerson?.Email) &&
                map.Email.Equals(gibbonPerson.Email, StringComparison.OrdinalIgnoreCase))
            {
                score += 50;
            }

            if (!string.IsNullOrWhiteSpace(map.Email) && 
                !string.IsNullOrWhiteSpace(moodleUser?.Email) &&
                map.Email.Equals(moodleUser.Email, StringComparison.OrdinalIgnoreCase))
            {
                score += 50;
            }

            // Username match
            if (!string.IsNullOrWhiteSpace(map.Username) && 
                !string.IsNullOrWhiteSpace(moodleUser?.Username) &&
                map.Username.Equals(moodleUser.Username, StringComparison.OrdinalIgnoreCase))
            {
                score += 30;
            }

            return Math.Min(score, 100);
        }
    }
}

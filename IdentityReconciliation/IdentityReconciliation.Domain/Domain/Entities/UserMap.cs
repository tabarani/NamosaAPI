namespace IdentityReconciliation.Domain.Entities
{
    public class UserMap
    {
        public Guid Id { get; private set; } = Guid.NewGuid();
        public int? MoodleId { get; set; }
        public int? GibbonId { get; set; }
        public string? Email { get; set; }
        public string? Username { get; set; }
        public int MatchConfidence { get; private set; }
        public Domain.Enums.MatchStatus Status { get; private set; } = Domain.Enums.MatchStatus.Pending;
        public DateTime CreatedAt { get; private set; }
        public DateTime UpdatedAt { get; private set; }
        public Guid? IdentityUserId { get; set; }

        public UserMap()
        {
            CreatedAt = DateTime.UtcNow;
            UpdatedAt = DateTime.UtcNow;
        }

        public void MarkAsLinked(int confidence)
        {
            if (confidence < 0 || confidence > 100)
                throw new ArgumentException("Match confidence must be between 0 and 100.", nameof(confidence));

            MatchConfidence = confidence;
            Status = Domain.Enums.MatchStatus.Linked;
            UpdatedAt = DateTime.UtcNow;
        }

        public void MarkAsConflict()
        {
            Status = Domain.Enums.MatchStatus.Conflict;
            UpdatedAt = DateTime.UtcNow;
        }
    }
}

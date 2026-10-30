import 'package:flutter/material.dart';

import '../core/app_state.dart';
import '../core/outbox.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/logo.dart';
import 'checkin.dart';
import 'fallback.dart';
import 'incident.dart';
import 'messages.dart';
import 'onboarding.dart';
import 'outbox_screen.dart';
import 'result_flow.dart';
import 'settings.dart';

class AgentHome extends StatefulWidget {
  const AgentHome({super.key});
  @override
  State<AgentHome> createState() => _AgentHomeState();
}

class _AgentHomeState extends State<AgentHome> {
  final app = AppState.instance;
  Map<String, dynamic>? onboarding;

  @override
  void initState() {
    super.initState();
    _loadOnboarding();
  }

  Future<void> _loadOnboarding() async {
    try {
      final o = await app.api.get('onboarding');
      if (mounted) setState(() => onboarding = o);
    } catch (_) {}
  }

  Future<void> _refresh() async {
    await app.refresh();
    await _loadOnboarding();
  }

  void go(Widget screen) => Navigator.push(
    context,
    MaterialPageRoute(builder: (_) => screen),
  ).then((_) => _refresh());

  OutboxItem? _latest(String kind) {
    final items = app.outbox.items.where((i) => i.kind == kind).toList();
    return items.isEmpty ? null : items.last;
  }

  @override
  Widget build(BuildContext context) => ListenableBuilder(
    listenable: app,
    builder: (context, _) {
      final unit = app.unit;
      final today = app.today;
      final checkIn = today['check_in'] as Map?;
      final result = today['result'] as Map?;
      final pendingCheckIn = _latest('check_in');
      final pendingResult = _latest('result');
      final pendingPhoto = _latest('photo');
      final waiting = app.outbox.waiting.length;
      final step = onboarding?['step'] as int? ?? 5;
      final name = (app.me?['name'] as String? ?? '').split(' ').first;

      final checkedIn =
          checkIn?['counts'] == true ||
          pendingCheckIn?.status == OutboxStatus.sent &&
              pendingCheckIn?.response?['status'] != 'pending_review';
      final checkInNote = checkIn != null
          ? (checkIn['counts'] == true
                ? 'Verified at ${hhmm(DateTime.parse(checkIn['at']))} · ${checkIn['distance_m'] ?? '?'} m'
                : checkIn['status'] == 'pending_review'
                ? 'Waiting for your supervisor to confirm'
                : 'Rejected. Check in again at the unit')
          : pendingCheckIn?.status == OutboxStatus.waiting
          ? 'Saved · sends when there is network'
          : 'Inside the unit area, with GPS';
      final resultDone =
          result != null ||
          pendingResult != null && pendingResult.status != OutboxStatus.refused;
      final sentBack = result?['status'] == 'sent_back';
      final resultNote = sentBack
          ? 'Sent back: ${result?['review_note'] ?? 'see messages'}'
          : result != null
          ? 'Signed · ref ${result['fingerprint']} · ${result['status'] == 'approved' ? 'verified' : 'waiting for verification'}'
          : pendingResult != null
          ? (pendingResult.status == OutboxStatus.refused
                ? 'Not accepted: ${pendingResult.error}'
                : pendingResult.status == OutboxStatus.sent
                ? 'Signed · ref ${pendingResult.meta['fingerprint']} · sent, waiting for verification'
                : 'Signed and saved · ref ${pendingResult.meta['fingerprint']} · waiting to send')
          : 'Photo of the EC8A, figures, sign';
      final photoDone =
          result?['has_photo'] == true ||
          pendingPhoto?.status == OutboxStatus.sent;
      final photoNote = photoDone
          ? 'Uploaded'
          : pendingPhoto != null
          ? 'Uploads by itself when data returns'
          : 'Sent with the result';

      final tasks = [
        (
          Icons.where_to_vote_outlined,
          'Check in at your unit',
          checkInNote,
          checkedIn,
          sentBack
              ? null
              : (checkedIn ? null : () => go(const CheckInScreen())),
        ),
        (
          Icons.fact_check_outlined,
          'Submit the result',
          resultNote,
          resultDone && !sentBack,
          checkedIn || resultDone
              ? () => go(
                  resultDone
                      ? ResultFlow(correctionOf: result)
                      : const ResultFlow(),
                )
              : null,
        ),
        (
          Icons.photo_camera_outlined,
          'EC8A photo sent',
          photoNote,
          photoDone,
          null,
        ),
        (
          Icons.report_outlined,
          'Report incidents',
          '${today['incidents'] ?? 0} reported today',
          false,
          () => go(const IncidentScreen()),
        ),
      ];

      return Scaffold(
        body: RefreshIndicator(
          onRefresh: _refresh,
          child: ListView(
            padding: EdgeInsets.zero,
            children: [
              Container(
                color: C.navy,
                padding: EdgeInsets.fromLTRB(
                  20,
                  MediaQuery.of(context).padding.top + 14,
                  12,
                  22,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const PinLogo(size: 26),
                        const SizedBox(width: 8),
                        const Wordmark(size: 20),
                        const SizedBox(width: 10),
                        _SyncDot(online: app.outbox.online, waiting: waiting),
                        const Spacer(),
                        IconButton(
                          onPressed: () => go(const SettingsScreen()),
                          icon: const Icon(
                            Icons.account_circle_outlined,
                            color: Colors.white,
                          ),
                          tooltip: 'Profile and settings',
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Text(
                      '${_greeting()}, $name',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 22,
                        fontWeight: FontWeight.w800,
                      ),
                    ),
                    const SizedBox(height: 14),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: C.navy2,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: unit == null
                          ? Text(
                              app.me?['is_reserve'] == true
                                  ? "You're a reserve agent. Your supervisor will send you to a unit if needed."
                                  : 'No polling unit assigned yet. Call your supervisor.',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 15,
                              ),
                            )
                          : Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'YOUR POLLING UNIT',
                                  style: TextStyle(
                                    color: Color(0xFFFFB48A),
                                    fontSize: 12,
                                    fontWeight: FontWeight.w800,
                                    letterSpacing: 1.2,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  '${unit['code']} · ${unit['name']}',
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 18,
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                Text(
                                  '${unit['ward']} · ${unit['lga']}',
                                  style: const TextStyle(
                                    color: Color(0xFFC9D1DE),
                                    fontSize: 14,
                                  ),
                                ),
                              ],
                            ),
                    ),
                  ],
                ),
              ),
              Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (app.rules['declared'] == true) ...[
                      const InfoBox(
                        'The result has been declared. Thank you for protecting the vote.',
                        tone: 'navy',
                        icon: Icons.verified_outlined,
                      ),
                      const SizedBox(height: 14),
                    ] else if (step < 5 && unit != null) ...[
                      _GetReady(
                        step: step,
                        onTap: () =>
                            go(OnboardingScreen(onboarding: onboarding ?? {})),
                      ),
                      const SizedBox(height: 14),
                    ],
                    if (sentBack) ...[
                      InfoBox(
                        result?['review_note'] ??
                            'The verifier sent your result back.',
                        title: 'Your result was sent back',
                        tone: 'bad',
                        icon: Icons.undo,
                      ),
                      const SizedBox(height: 8),
                      FilledButton(
                        onPressed: () => go(ResultFlow(correctionOf: result)),
                        child: const Text('Send a correction'),
                      ),
                      const SizedBox(height: 14),
                    ],
                    Row(
                      children: [
                        const Text(
                          'Election-day tasks',
                          style: TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w800,
                          ),
                        ),
                        const Spacer(),
                        Text(
                          '${tasks.where((t) => t.$4).length} of 3 done',
                          style: const TextStyle(color: C.ink2, fontSize: 13),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    SectionCard(
                      padding: EdgeInsets.zero,
                      child: Column(
                        children: [
                          for (var i = 0; i < tasks.length; i++) ...[
                            if (i > 0) const Divider(),
                            _TaskTile(
                              icon: tasks[i].$1,
                              title: tasks[i].$2,
                              note: tasks[i].$3,
                              done: tasks[i].$4,
                              onTap: tasks[i].$5,
                              n: i + 1,
                            ),
                          ],
                        ],
                      ),
                    ),
                    const SizedBox(height: 14),
                    GridView.count(
                      crossAxisCount: 2,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      mainAxisSpacing: 10,
                      crossAxisSpacing: 10,
                      childAspectRatio: 2.3,
                      children: [
                        _Quick(
                          Icons.report_outlined,
                          'Report incident',
                          () => go(const IncidentScreen()),
                          tone: C.action,
                        ),
                        _Quick(
                          Icons.chat_bubble_outline,
                          'Messages',
                          () => go(const MessagesScreen()),
                          badge: app.unread,
                        ),
                        _Quick(
                          Icons.outbox_outlined,
                          'Outbox',
                          () => go(const OutboxScreen()),
                          badge: waiting,
                        ),
                        _Quick(
                          Icons.sms_outlined,
                          'If the app fails',
                          () => go(const FallbackScreen()),
                        ),
                      ],
                    ),
                    const SizedBox(height: 14),
                    Text(
                      waiting == 0
                          ? 'All records sent'
                          : '$waiting waiting to send${app.outbox.online ? '' : ' · no connection'}',
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: waiting == 0 ? C.good : C.warn,
                        fontWeight: FontWeight.w600,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      );
    },
  );

  String _greeting() {
    final h = DateTime.now().hour;
    return h < 12
        ? 'Good morning'
        : h < 17
        ? 'Good afternoon'
        : 'Good evening';
  }
}

class _SyncDot extends StatelessWidget {
  const _SyncDot({required this.online, required this.waiting});
  final bool online;
  final int waiting;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
    decoration: BoxDecoration(
      color: online ? const Color(0x3317694B) : const Color(0x33E8590C),
      borderRadius: BorderRadius.circular(20),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 7,
          height: 7,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: online ? const Color(0xFF5BD39A) : C.orange,
          ),
        ),
        const SizedBox(width: 5),
        Text(
          online ? 'Online' : 'Offline',
          style: const TextStyle(
            color: Colors.white,
            fontSize: 12,
            fontWeight: FontWeight.w700,
          ),
        ),
      ],
    ),
  );
}

class _GetReady extends StatelessWidget {
  const _GetReady({required this.step, required this.onTap});
  final int step;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Semantics(
    button: true,
    child: Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: C.action, width: 1.5),
          ),
          child: Row(
            children: [
              const Icon(Icons.school_outlined, color: C.action),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text(
                      'Get ready for election day',
                      style: TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                      ),
                    ),
                    Text(
                      step < 3
                          ? 'Do a practice run, then the quiz'
                          : step < 4
                          ? 'Pass the 10-question quiz'
                          : 'Confirm you will be at your unit',
                      style: const TextStyle(color: C.ink2, fontSize: 13),
                    ),
                  ],
                ),
              ),
              const Icon(Icons.chevron_right),
            ],
          ),
        ),
      ),
    ),
  );
}

class _TaskTile extends StatelessWidget {
  const _TaskTile({
    required this.icon,
    required this.title,
    required this.note,
    required this.done,
    required this.onTap,
    required this.n,
  });
  final IconData icon;
  final String title, note;
  final bool done;
  final VoidCallback? onTap;
  final int n;
  @override
  Widget build(BuildContext context) => ListTile(
    onTap: onTap,
    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
    leading: CircleAvatar(
      radius: 17,
      backgroundColor: done ? C.good : const Color(0xFFEEF0F4),
      child: done
          ? const Icon(Icons.check, color: Colors.white, size: 18)
          : Icon(icon, color: C.navy, size: 18),
    ),
    title: Text(
      title,
      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15),
    ),
    subtitle: Text(
      note,
      style: TextStyle(
        fontSize: 12.5,
        color: note.startsWith('Sent back') || note.startsWith('Not accepted')
            ? C.bad
            : C.ink2,
      ),
    ),
    trailing: onTap == null
        ? null
        : (done
              ? const Icon(Icons.chevron_right, color: C.ink3)
              : const Text(
                  'Start',
                  style: TextStyle(
                    color: C.action,
                    fontWeight: FontWeight.w800,
                  ),
                )),
  );
}

class _Quick extends StatelessWidget {
  const _Quick(
    this.icon,
    this.label,
    this.onTap, {
    this.badge = 0,
    this.tone = C.navy,
  });
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final int badge;
  final Color tone;
  @override
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    borderRadius: BorderRadius.circular(14),
    child: InkWell(
      borderRadius: BorderRadius.circular(14),
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: C.line),
        ),
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Row(
          children: [
            Icon(icon, color: tone, size: 22),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                label,
                style: const TextStyle(
                  fontWeight: FontWeight.w700,
                  fontSize: 13.5,
                ),
              ),
            ),
            if (badge > 0)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                decoration: BoxDecoration(
                  color: C.action,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(
                  '$badge',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 12,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
          ],
        ),
      ),
    ),
  );
}

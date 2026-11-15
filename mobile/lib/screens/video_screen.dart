import 'dart:async';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:uuid/uuid.dart';

import '../core/app_state.dart';
import '../core/geo.dart';
import '../core/outbox.dart';
import '../core/signing.dart';
import '../core/video_files.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'outbox_screen.dart';

/// A recording kept on the phone, ready to queue.
class RecordedVideo {
  RecordedVideo(this.id, this.path, this.duration, this.bytes);
  final String id;
  final String path;
  final Duration duration;
  final int bytes;
}

/// What a video can be of. The keys match VideoClip::KINDS on the server.
const videoKinds = {
  'result': ('Result announcement', Icons.campaign_outlined),
  'incident': ('Incident', Icons.report_outlined),
  'collation': ('Collation', Icons.layers_outlined),
  'other': ('Something else', Icons.videocam_outlined),
};

/// Longest recording allowed; at the app's medium quality this is roughly 30 MB.
const maxVideoLength = Duration(minutes: 3);

String videoSize(int bytes) => bytes >= 1024 * 1024
    ? '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB'
    : '${(bytes / 1024).ceil()} KB';

String mmss(Duration d) =>
    '${d.inMinutes.toString().padLeft(2, '0')}:${(d.inSeconds % 60).toString().padLeft(2, '0')}';

/// Puts a recording in the outbox. It stays on the phone and goes up in pieces whenever there
/// is a connection, carrying on where it stopped. With [afterIncident], it is linked to that
/// incident once the incident itself has been sent.
Future<OutboxItem> queueVideo(
  RecordedVideo v, {
  required String kind,
  Map? race,
  String? note,
  String? afterIncident,
}) async {
  final app = AppState.instance;
  Fix? fix;
  try {
    fix = await Geo.current().timeout(const Duration(seconds: 8));
  } catch (_) {}
  final sha = await VideoFiles.sha256(v.path);
  final where = app.unit?['code'] ?? app.me?['ward']?['name'] ?? '';
  return app.outbox.add(
    kind: 'video',
    path: 'videos',
    title:
        'Video: ${videoKinds[kind]!.$1}${race != null && app.races.length > 1 ? ' · ${race['name']}' : ''}${where == '' ? '' : ' · $where'}',
    after: afterIncident,
    fields: {
      'client_id': v.id,
      'kind': kind,
      'mime': 'video/mp4',
      'size': v.bytes,
      'sha256': sha,
      'duration_s': v.duration.inSeconds,
      'captured_at': Canonical.timestamp(DateTime.now().subtract(v.duration)),
      'latitude': fix?.latitude,
      'longitude': fix?.longitude,
      'note': (note ?? '').trim().isEmpty ? null : note!.trim(),
      'election': race?['code'],
      if (afterIncident != null) 'incident_id': '{after.id}',
    },
    meta: {
      'file': v.path,
      'size': v.bytes,
      'received': 0,
      'duration_s': v.duration.inSeconds,
    },
  );
}

/// Record a video of a result being announced, an incident or a collation centre: choose what
/// it shows, record, then keep it. It uploads by itself, now or when the network returns.
class VideoScreen extends StatefulWidget {
  const VideoScreen({super.key, this.kind});
  final String? kind;
  @override
  State<VideoScreen> createState() => _VideoScreenState();
}

class _VideoScreenState extends State<VideoScreen> {
  final app = AppState.instance;
  late String kind =
      widget.kind ??
      (app.role == 'collation_agent'
          ? 'collation'
          : app.unit != null
          ? 'result'
          : 'other');
  late Map? race = app.races.isEmpty ? null : app.races.first;
  final note = TextEditingController();
  RecordedVideo? video;
  bool saving = false;
  OutboxItem? saved;

  Future<void> record() async {
    final v = await Navigator.push<RecordedVideo>(
      context,
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => RecorderScreen(label: videoKinds[kind]!.$1),
      ),
    );
    if (v == null || !mounted) return;
    // A retake replaces the earlier recording, which is removed from the phone.
    if (video != null) await VideoFiles.delete(video!.path);
    setState(() => video = v);
  }

  Future<void> save() async {
    setState(() => saving = true);
    final item = await queueVideo(
      video!,
      kind: kind,
      race: kind == 'result' || kind == 'collation' ? race : null,
      note: note.text,
    );
    if (!mounted) return;
    setState(() {
      saving = false;
      saved = item;
    });
  }

  Future<void> discard() async {
    if (video != null) await VideoFiles.delete(video!.path);
    if (mounted) setState(() => video = null);
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: video == null || saved != null,
    onPopInvokedWithResult: (didPop, _) async {
      if (didPop) return;
      final leave = await showDialog<bool>(
        context: context,
        builder: (c) => AlertDialog(
          title: const Text('Delete this video?'),
          content: const Text(
            "You recorded a video but haven't kept it. Leaving deletes it from this phone.",
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(c, false),
              child: const Text('Stay'),
            ),
            TextButton(
              onPressed: () => Navigator.pop(c, true),
              child: const Text('Delete it'),
            ),
          ],
        ),
      );
      if (leave == true) {
        await discard();
        if (context.mounted) Navigator.pop(context);
      }
    },
    child: Scaffold(
      appBar: AppBar(
        title: Text(saved != null ? 'Video kept' : 'Record video'),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: saved != null ? _done() : _form(),
      ),
    ),
  );

  List<Widget> _form() => [
    if (!VideoFiles.supported) ...[
      const InfoBox('Video recording works in the Android app.', tone: 'warn'),
      const SizedBox(height: 14),
    ],
    const Label('What does it show?'),
    Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        for (final e in videoKinds.entries)
          if (e.key != 'collation' || app.role == 'collation_agent')
            ChoiceChip(
              avatar: Icon(e.value.$2, size: 18),
              label: Text(e.value.$1),
              showCheckmark: false,
              selected: kind == e.key,
              onSelected: (_) => setState(() => kind = e.key),
            ),
      ],
    ),
    if ((kind == 'result' || kind == 'collation') && app.races.length > 1) ...[
      const SizedBox(height: 16),
      const Label('Which race?'),
      Wrap(
        spacing: 8,
        runSpacing: 8,
        children: [
          for (final r in app.races)
            ChoiceChip(
              avatar: RaceTag(r['type'] as String?, size: 18),
              label: Text('${r['name']}'),
              selected: race?['id'] == r['id'],
              showCheckmark: false,
              onSelected: (_) => setState(() => race = r),
            ),
        ],
      ),
    ],
    const SizedBox(height: 16),
    const Label('A few words about it (optional)'),
    TextField(
      controller: note,
      maxLength: 500,
      maxLines: 2,
      decoration: const InputDecoration(
        hintText: 'e.g. Presiding officer reading out the EC8A',
      ),
    ),
    const SizedBox(height: 4),
    if (video == null) ...[
      const InfoBox(
        'Hold the phone steady and keep it short: a minute is about 10 MB. '
        'Never put yourself at risk to record.',
        tone: 'warn',
        icon: Icons.health_and_safety_outlined,
      ),
      const SizedBox(height: 16),
      FilledButton.icon(
        key: const Key('start-recording'),
        onPressed: VideoFiles.supported ? record : null,
        icon: const Icon(Icons.fiber_manual_record),
        label: const Text('Start recording'),
      ),
    ] else ...[
      _RecordedCard(video: video!),
      const SizedBox(height: 16),
      FilledButton.icon(
        key: const Key('keep-video'),
        onPressed: saving ? null : save,
        icon: const Icon(Icons.check),
        label: Text(saving ? 'Preparing…' : 'Keep and send'),
      ),
      const SizedBox(height: 4),
      TextButton.icon(
        onPressed: saving ? null : record,
        icon: const Icon(Icons.replay),
        label: const Text('Record again'),
      ),
    ],
  ];

  List<Widget> _done() => [
    const SizedBox(height: 12),
    const Icon(Icons.cloud_upload_outlined, size: 56, color: C.good),
    const SizedBox(height: 12),
    const Text(
      'Your video is saved on this phone',
      textAlign: TextAlign.center,
      style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
    ),
    const SizedBox(height: 8),
    Text(
      'It uploads by itself in the background while the app is open, and carries on '
      'where it stopped if the network drops. Results and incidents always go first.',
      textAlign: TextAlign.center,
      style: const TextStyle(color: C.ink2, fontSize: 15, height: 1.4),
    ),
    const SizedBox(height: 20),
    ListenableBuilder(
      listenable: app.outbox,
      builder: (context, _) {
        final item = app.outbox.byId(saved!.id);
        return item == null
            ? const SizedBox.shrink()
            : VideoProgress(item: item);
      },
    ),
    const SizedBox(height: 20),
    OutlinedButton(
      onPressed: () => Navigator.pushReplacement(
        context,
        MaterialPageRoute(builder: (_) => const OutboxScreen()),
      ),
      child: const Text('See the outbox'),
    ),
    const SizedBox(height: 8),
    FilledButton(
      onPressed: () => Navigator.pop(context),
      child: const Text('Done'),
    ),
  ];
}

class _RecordedCard extends StatelessWidget {
  const _RecordedCard({required this.video});
  final RecordedVideo video;
  @override
  Widget build(BuildContext context) => SectionCard(
    child: Row(
      children: [
        Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(
            color: C.navy,
            borderRadius: BorderRadius.circular(12),
          ),
          child: const Icon(Icons.videocam, color: Colors.white),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Recorded ${mmss(video.duration)}',
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w800,
                ),
              ),
              Text(
                '${videoSize(video.bytes)} to send',
                style: const TextStyle(color: C.ink2),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

/// How far a video's upload has got, for the outbox and the screen after recording.
class VideoProgress extends StatelessWidget {
  const VideoProgress({super.key, required this.item});
  final OutboxItem item;
  @override
  Widget build(BuildContext context) {
    final size = (item.meta['size'] as num?)?.toInt() ?? 1;
    final sent = item.status == OutboxStatus.sent;
    final got = sent ? size : ((item.meta['received'] as num?)?.toInt() ?? 0);
    final share = size == 0 ? 0.0 : (got / size).clamp(0.0, 1.0);
    final text = sent
        ? 'Uploaded · ${videoSize(size)}'
        : item.status == OutboxStatus.refused
        ? 'Not accepted: ${item.error}'
        : '${(share * 100).floor()}% · ${videoSize(got)} of ${videoSize(size)}${item.error == 'No connection' ? ' · waiting for network' : ''}';
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(4),
          child: LinearProgressIndicator(
            value: share,
            minHeight: 6,
            backgroundColor: const Color(0xFFE6E9EE),
            color: sent ? C.good : C.navy,
          ),
        ),
        const SizedBox(height: 6),
        Text(
          text,
          style: TextStyle(
            fontSize: 12.5,
            color: item.status == OutboxStatus.refused ? C.bad : C.ink2,
          ),
        ),
      ],
    );
  }
}

/// The camera, full screen: one big button to start and stop, a timer, and a switch between
/// the back and front cameras. Medium quality keeps a minute to about 10 MB for weak networks.
class RecorderScreen extends StatefulWidget {
  const RecorderScreen({super.key, required this.label});
  final String label;
  @override
  State<RecorderScreen> createState() => _RecorderScreenState();
}

class _RecorderScreenState extends State<RecorderScreen>
    with WidgetsBindingObserver {
  List<CameraDescription> cameras = [];
  CameraController? controller;
  int cameraIndex = 0;
  String? problem;
  bool recording = false, stopping = false;
  Duration elapsed = Duration.zero;
  Timer? ticker;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _start();
  }

  Future<void> _start() async {
    setState(() => problem = null);
    try {
      cameras = await availableCameras();
      if (cameras.isEmpty) throw CameraException('none', 'No camera');
      final back = cameras.indexWhere(
        (c) => c.lensDirection == CameraLensDirection.back,
      );
      cameraIndex = back < 0 ? 0 : back;
      await _open();
    } on CameraException catch (e) {
      setState(() => problem = _explain(e));
    }
  }

  Future<void> _open() async {
    final old = controller;
    controller = null;
    if (mounted) setState(() {});
    await old?.dispose();
    final c = CameraController(
      cameras[cameraIndex],
      ResolutionPreset.medium,
      enableAudio: true,
      // Enough to read a result sheet held up to the camera, small enough for a weak network,
      // and three minutes stays under the 25 MB the AI checks can send without ffmpeg.
      videoBitrate: 1000 * 1000,
      audioBitrate: 64 * 1000,
    );
    try {
      await c.initialize();
      controller = c;
    } on CameraException catch (e) {
      await c.dispose();
      problem = _explain(e);
    }
    if (mounted) setState(() {});
  }

  String _explain(CameraException e) => switch (e.code) {
    'CameraAccessDenied' ||
    'CameraAccessDeniedWithoutPrompt' ||
    'CameraAccessRestricted' => 'PollProof needs the camera to record. Allow it under Settings, Apps, PollProof, Permissions.',
    'AudioAccessDenied' ||
    'AudioAccessDeniedWithoutPrompt' ||
    'AudioAccessRestricted' => 'PollProof needs the microphone to record sound. Allow it under Settings, Apps, PollProof, Permissions.',
    _ => "The camera couldn't start. Close other camera apps and try again.",
  };

  Future<void> _toggle() async {
    final c = controller;
    if (c == null || stopping) return;
    if (!recording) {
      HapticFeedback.mediumImpact();
      await c.startVideoRecording();
      setState(() {
        recording = true;
        elapsed = Duration.zero;
      });
      ticker = Timer.periodic(const Duration(seconds: 1), (_) {
        setState(() => elapsed += const Duration(seconds: 1));
        if (elapsed >= maxVideoLength) _stop();
      });
    } else {
      await _stop();
    }
  }

  Future<void> _stop() async {
    final c = controller;
    if (c == null || !recording || stopping) return;
    stopping = true;
    ticker?.cancel();
    HapticFeedback.mediumImpact();
    try {
      final file = await c.stopVideoRecording();
      final id = const Uuid().v4();
      final kept = await VideoFiles.keep(file.path, id);
      final bytes = await VideoFiles.size(kept);
      if (mounted) {
        Navigator.pop(context, RecordedVideo(id, kept, elapsed, bytes));
      }
    } on CameraException catch (e) {
      setState(() {
        problem = _explain(e);
        recording = false;
        stopping = false;
      });
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Leaving the app (a call, the home button) ends the recording and keeps what was filmed.
    if (state == AppLifecycleState.inactive && recording) _stop();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    ticker?.cancel();
    controller?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final c = controller;
    final pad = MediaQuery.of(context).padding;
    return PopScope(
      canPop: !recording,
      child: Scaffold(
        backgroundColor: Colors.black,
        body: Stack(
          children: [
            Positioned.fill(
              child: problem != null
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(28),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(
                              Icons.videocam_off_outlined,
                              color: Colors.white70,
                              size: 44,
                            ),
                            const SizedBox(height: 14),
                            Text(
                              problem!,
                              textAlign: TextAlign.center,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 15,
                                height: 1.4,
                              ),
                            ),
                            const SizedBox(height: 18),
                            OutlinedButton(
                              style: OutlinedButton.styleFrom(
                                foregroundColor: Colors.white,
                                side: const BorderSide(color: Colors.white54),
                              ),
                              onPressed: _start,
                              child: const Text('Try again'),
                            ),
                          ],
                        ),
                      ),
                    )
                  : c == null || !c.value.isInitialized
                  ? const Center(
                      child: CircularProgressIndicator(color: Colors.white),
                    )
                  : Center(child: CameraPreview(c)),
            ),
            // Top: close, what is being recorded, and the clock while recording.
            Positioned(
              top: pad.top + 8,
              left: 8,
              right: 8,
              child: Row(
                children: [
                  IconButton(
                    onPressed: recording ? null : () => Navigator.pop(context),
                    icon: Icon(
                      Icons.close,
                      color: recording ? Colors.white30 : Colors.white,
                    ),
                    tooltip: 'Close',
                  ),
                  Expanded(
                    child: Center(
                      child: recording
                          ? Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 12,
                                vertical: 6,
                              ),
                              decoration: BoxDecoration(
                                color: const Color(0xCCB42318),
                                borderRadius: BorderRadius.circular(20),
                              ),
                              child: Text(
                                '● ${mmss(elapsed)} / ${mmss(maxVideoLength)}',
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontWeight: FontWeight.w800,
                                  fontFeatures: [FontFeature.tabularFigures()],
                                ),
                              ),
                            )
                          : Text(
                              widget.label,
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w700,
                                fontSize: 15,
                              ),
                            ),
                    ),
                  ),
                  const SizedBox(width: 48),
                ],
              ),
            ),
            // Bottom: record and stop in the middle, switch camera to the side.
            Positioned(
              left: 0,
              right: 0,
              bottom: pad.bottom + 28,
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                children: [
                  const SizedBox(width: 56),
                  Semantics(
                    button: true,
                    label: recording ? 'Stop recording' : 'Start recording',
                    child: GestureDetector(
                      key: const Key('record-button'),
                      onTap: c == null ? null : _toggle,
                      child: Container(
                        width: 78,
                        height: 78,
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          border: Border.all(color: Colors.white, width: 4),
                        ),
                        alignment: Alignment.center,
                        child: AnimatedContainer(
                          duration: const Duration(milliseconds: 180),
                          width: recording ? 30 : 60,
                          height: recording ? 30 : 60,
                          decoration: BoxDecoration(
                            color: const Color(0xFFE5322D),
                            borderRadius: BorderRadius.circular(
                              recording ? 6 : 30,
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                  SizedBox(
                    width: 56,
                    child: cameras.length > 1 && !recording
                        ? IconButton(
                            onPressed: () {
                              cameraIndex = (cameraIndex + 1) % cameras.length;
                              _open();
                            },
                            icon: const Icon(
                              Icons.cameraswitch_outlined,
                              color: Colors.white,
                              size: 30,
                            ),
                            tooltip: 'Switch camera',
                          )
                        : null,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

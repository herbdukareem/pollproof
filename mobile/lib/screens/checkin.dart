import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:uuid/uuid.dart';

import '../core/app_state.dart';
import '../core/geo.dart';
import '../core/outbox.dart';
import '../core/signing.dart';
import '../theme.dart';
import '../widgets/common.dart';

class CheckInScreen extends StatefulWidget {
  const CheckInScreen({super.key});
  @override
  State<CheckInScreen> createState() => _CheckInScreenState();
}

class _CheckInScreenState extends State<CheckInScreen> {
  final app = AppState.instance;
  Fix? fix;
  String? gpsError;
  bool locating = true, sending = false;
  Uint8List? selfie;
  String? serverRefusal;
  OutboxItem? saved;

  int get radius => app.rules['geofence_radius_m'] ?? 100;
  int get maxAccuracy => app.rules['max_gps_accuracy_m'] ?? 30;
  bool get selfieRequired => app.rules['require_selfie'] == true;
  double? get unitLat => (app.unit?['latitude'] as num?)?.toDouble();
  double? get unitLng => (app.unit?['longitude'] as num?)?.toDouble();
  int? get distance => fix == null || unitLat == null
      ? null
      : Geo.distance(fix!.latitude, fix!.longitude, unitLat!, unitLng!);
  bool get weak => fix != null && fix!.accuracy > maxAccuracy;
  bool get inside => distance != null && distance! <= radius && !weak;

  @override
  void initState() {
    super.initState();
    locate();
  }

  Future<void> locate() async {
    setState(() {
      locating = true;
      gpsError = null;
      serverRefusal = null;
    });
    try {
      final f = await Geo.current();
      setState(() => fix = f);
    } catch (e) {
      setState(() => gpsError = e.toString());
    } finally {
      if (mounted) setState(() => locating = false);
    }
  }

  Future<void> takeSelfie() async {
    final x = await ImagePicker().pickImage(
      source: ImageSource.camera,
      preferredCameraDevice: CameraDevice.front,
      imageQuality: 60,
      maxWidth: 1024,
    );
    if (x != null) {
      final bytes = await x.readAsBytes();
      setState(() => selfie = bytes);
    }
  }

  Future<void> send({required bool review}) async {
    setState(() {
      sending = true;
      serverRefusal = null;
    });
    final files = <Map<String, String>>[];
    if (selfie != null) {
      files.add({
        'field': 'selfie',
        'filename': 'selfie.jpg',
        'blob': await app.outbox.saveBlob(selfie!),
      });
    }
    final item = await app.outbox.add(
      kind: 'check_in',
      path: 'check-ins',
      title: 'Check-in at ${app.unit?['code']}',
      fields: {
        'latitude': fix!.latitude,
        'longitude': fix!.longitude,
        'accuracy_m': fix!.accuracy.round(),
        'captured_at': Canonical.timestamp(DateTime.now()),
        'request_review': review,
        'client_ref': const Uuid().v4(),
      },
      files: files,
    );
    await app.outbox.flush();
    final now = app.outbox.byId(item.id)!;
    if (!mounted) return;
    if (now.status == OutboxStatus.refused) {
      // The server measured it differently (for example the unit's pin moved). Offer review.
      await app.outbox.dismiss(item.id);
      setState(() {
        serverRefusal = now.error;
        sending = false;
      });
      return;
    }
    setState(() {
      saved = now;
      sending = false;
    });
    app.refresh();
  }

  @override
  Widget build(BuildContext context) {
    final unit = app.unit;
    return Scaffold(
      appBar: AppBar(title: const Text('Check in')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 24),
        children: [
          if (saved != null)
            ..._done()
          else ...[
            Text(
              '${unit?['code']} · ${unit?['name']}',
              style: const TextStyle(
                color: C.ink2,
                fontWeight: FontWeight.w600,
              ),
            ),
            const SizedBox(height: 12),
            _locationCard(),
            const SizedBox(height: 14),
            if (fix != null &&
                (unitLat == null || !inside || serverRefusal != null))
              ..._outside()
            else if (fix != null) ...[
              SectionCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      selfieRequired
                          ? 'Take a selfie at the unit'
                          : 'Selfie at the unit (optional)',
                      style: const TextStyle(
                        fontWeight: FontWeight.w800,
                        fontSize: 15,
                      ),
                    ),
                    const SizedBox(height: 4),
                    const Text(
                      'Show your face clearly with the polling unit behind you.',
                      style: TextStyle(color: C.ink2, fontSize: 13.5),
                    ),
                    const SizedBox(height: 12),
                    if (selfie != null)
                      ClipRRect(
                        borderRadius: BorderRadius.circular(12),
                        child: Image.memory(
                          selfie!,
                          height: 180,
                          width: double.infinity,
                          fit: BoxFit.cover,
                        ),
                      ),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: takeSelfie,
                      icon: const Icon(Icons.photo_camera_front_outlined),
                      label: Text(selfie == null ? 'Open camera' : 'Retake'),
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 12),
              const Text(
                'Time and location are recorded automatically. Your supervisor sees your check-in straight away.',
                style: TextStyle(color: C.ink2, fontSize: 13),
              ),
              const SizedBox(height: 16),
              FilledButton(
                key: const Key('confirm-checkin'),
                onPressed: sending || (selfieRequired && selfie == null)
                    ? null
                    : () => send(review: false),
                child: Text(sending ? 'Checking in…' : 'Confirm check-in'),
              ),
            ],
          ],
        ],
      ),
    );
  }

  Widget _locationCard() {
    if (locating) {
      return const SectionCard(
        child: Row(
          children: [
            SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(strokeWidth: 2.5),
            ),
            SizedBox(width: 12),
            Text('Finding your location…'),
          ],
        ),
      );
    }
    if (gpsError != null) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          InfoBox(gpsError!, tone: 'bad', icon: Icons.location_off_outlined),
          const SizedBox(height: 10),
          OutlinedButton(onPressed: locate, child: const Text('Try again')),
        ],
      );
    }
    final ok = inside && serverRefusal == null;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: ok ? C.goodBg : C.badBg,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Row(
        children: [
          Icon(
            ok ? Icons.where_to_vote : Icons.wrong_location_outlined,
            color: ok ? C.good : C.action,
            size: 30,
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  ok
                      ? 'Inside the unit area'
                      : unitLat == null
                      ? 'This unit has no GPS point yet'
                      : "You're ${distance ?? '?'} m from ${app.unit?['code']}",
                  style: TextStyle(
                    fontWeight: FontWeight.w800,
                    fontSize: 16,
                    color: ok ? C.good : C.bad,
                  ),
                ),
                Text(
                  ok
                      ? '$distance m from ${app.unit?['code']} · accuracy ${fix!.accuracy.round()} m'
                      : 'GPS accuracy ${fix!.accuracy.round()} m',
                  style: const TextStyle(fontSize: 13, color: C.ink2),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  List<Widget> _outside() => [
    if (serverRefusal != null) ...[
      InfoBox(serverRefusal!, tone: 'bad'),
      const SizedBox(height: 10),
    ],
    if (unitLat == null)
      const InfoBox(
        'Your supervisor will confirm your check-in. Send it now with a selfie so they can see you are at the unit.',
        tone: 'warn',
      )
    else if (weak)
      InfoBox(
        'Weak GPS · ${fix!.accuracy.round()} m accuracy. Step outside or away from tall walls, then try again.',
        tone: 'warn',
      )
    else
      InfoBox(
        'Check-in only works inside the unit area ($radius m). Move closer to the polling unit and try again.',
        tone: 'warn',
      ),
    const SizedBox(height: 14),
    FilledButton(onPressed: locate, child: const Text('Try again')),
    const SizedBox(height: 10),
    if (selfie != null) ...[
      ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: Image.memory(selfie!, height: 120, fit: BoxFit.cover),
      ),
      const SizedBox(height: 8),
    ],
    OutlinedButton(
      onPressed: takeSelfie,
      child: Text(selfie == null ? 'Take a selfie first' : 'Retake selfie'),
    ),
    const SizedBox(height: 10),
    OutlinedButton(
      key: const Key('ask-supervisor'),
      onPressed: sending || selfie == null ? null : () => send(review: true),
      child: const Text("I'm at the unit, ask supervisor to confirm"),
    ),
    const SizedBox(height: 8),
    const Text(
      'Your selfie and location are sent to your ward supervisor, who can approve the check-in.',
      style: TextStyle(color: C.ink2, fontSize: 13),
    ),
  ];

  List<Widget> _done() {
    final res = saved!.response;
    final pending = saved!.status == OutboxStatus.waiting;
    final review =
        res?['status'] == 'pending_review' ||
        (pending && saved!.fields['request_review'] == true);
    return [
      const SizedBox(height: 30),
      Icon(
        review || pending ? Icons.schedule : Icons.verified,
        size: 72,
        color: review || pending ? C.warn : C.good,
      ),
      const SizedBox(height: 12),
      Text(
        pending
            ? 'Check-in saved'
            : review
            ? 'Sent to your supervisor'
            : "You're checked in",
        textAlign: TextAlign.center,
        style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800),
      ),
      const SizedBox(height: 6),
      Text(
        pending
            ? 'No connection right now. It sends by itself as soon as there is network.'
            : review
            ? 'Your supervisor will confirm it. You can carry on.'
            : 'Verified at ${hhmm(DateTime.now())}. Your supervisor can see you are at the unit.',
        textAlign: TextAlign.center,
        style: const TextStyle(color: C.ink2, fontSize: 15),
      ),
      const SizedBox(height: 28),
      FilledButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Back to home'),
      ),
    ];
  }
}

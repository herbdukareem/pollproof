import 'dart:async';
import 'dart:math';

import 'package:geolocator/geolocator.dart';

class Fix {
  Fix(this.latitude, this.longitude, this.accuracy);
  final double latitude;
  final double longitude;
  final double accuracy;
}

class GeoError implements Exception {
  GeoError(this.message);
  final String message;
  @override
  String toString() => message;
}

/// Location only when the agent checks in or submits; never in the background.
class Geo {
  static Future<Fix> current() async {
    await _ready();
    final pos = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 30),
      ),
    );
    return Fix(pos.latitude, pos.longitude, pos.accuracy);
  }

  /// The sharpest fix within [within]. A phone's first position is often rough (40 to 60 m)
  /// and tightens over the next seconds, so keep listening and stop as soon as [enough] says
  /// the fix settles the question. [onFix] sees each better fix as it comes.
  static Future<Fix> settle({
    required bool Function(Fix) enough,
    Duration within = const Duration(seconds: 20),
    void Function(Fix)? onFix,
  }) async {
    await _ready();
    final done = Completer<Fix>();
    Fix? best;
    StreamSubscription<Position>? sub;
    Timer? timer;
    void finish([Object? error]) {
      if (done.isCompleted) return;
      sub?.cancel();
      timer?.cancel();
      if (best != null) {
        done.complete(best);
      } else {
        done.completeError(
          error is GeoError
              ? error
              : GeoError(
                  'No GPS signal yet. Step into the open and try again.',
                ),
        );
      }
    }

    sub =
        Geolocator.getPositionStream(
          locationSettings: const LocationSettings(
            accuracy: LocationAccuracy.best,
            distanceFilter: 0,
          ),
        ).listen((p) {
          final f = Fix(p.latitude, p.longitude, p.accuracy);
          if (best == null || f.accuracy < best!.accuracy) {
            best = f;
            onFix?.call(f);
          }
          if (enough(best!)) finish();
        }, onError: finish);
    timer = Timer(within, finish);
    return done.future;
  }

  static Future<void> _ready() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw GeoError('Turn on location (GPS) in your phone settings.');
    }
    var p = await Geolocator.checkPermission();
    if (p == LocationPermission.denied) {
      p = await Geolocator.requestPermission();
    }
    if (p == LocationPermission.denied ||
        p == LocationPermission.deniedForever) {
      throw GeoError(
        'PollProof needs your location to check you in. Allow it in your phone settings.',
      );
    }
  }

  /// Whether a fix puts the agent inside the unit area. Same rule as the server: the position
  /// is within the radius and either the GPS is good, or it is rougher (up to twice the accepted
  /// error) but its whole margin of error, the circle the phone says the agent is somewhere in,
  /// lies inside the radius.
  static bool insideArea(
    int distance,
    double accuracy,
    int radius,
    int maxAccuracy,
  ) =>
      distance <= radius &&
      (accuracy <= maxAccuracy ||
          (accuracy <= 2 * maxAccuracy && distance + accuracy <= radius));

  /// Same formula as the server (haversine, metres).
  static int distance(double lat1, double lng1, double lat2, double lng2) {
    const r = 6371000.0;
    double rad(double d) => d * pi / 180;
    final dLat = rad(lat2 - lat1), dLng = rad(lng2 - lng1);
    final a =
        sin(dLat / 2) * sin(dLat / 2) +
        cos(rad(lat1)) * cos(rad(lat2)) * sin(dLng / 2) * sin(dLng / 2);
    return (2 * r * asin(sqrt(a))).round();
  }
}

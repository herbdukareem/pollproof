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
    final pos = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 30),
      ),
    );
    return Fix(pos.latitude, pos.longitude, pos.accuracy);
  }

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

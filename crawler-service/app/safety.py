"""URL and network safety checks (guide section 7.3).

Every URL is validated before each request, including after every redirect, so a host
that re-resolves to a private address mid-crawl (DNS rebinding) is rejected.
"""

from __future__ import annotations

import ipaddress
import os
import socket
from typing import Callable
from urllib.parse import urlsplit, urlunsplit

ALLOWED_SCHEMES = {"http", "https"}
BLOCKED_HOSTNAMES = {"localhost", "metadata", "metadata.google.internal", "instance-data"}
BLOCKED_SUFFIXES = (".localhost", ".local", ".internal", ".lan", ".home", ".corp", ".intranet")
EXTRA_BLOCKED_NETWORKS = [
    ipaddress.ip_network(n)
    for n in ("100.64.0.0/10", "192.0.0.0/24", "198.18.0.0/15", "0.0.0.0/8", "169.254.169.254/32", "fd00:ec2::254/128")
]

Resolver = Callable[[str], list[str]]


class UnsafeUrlError(ValueError):
    pass


def allow_private_hosts() -> bool:
    return os.getenv("CRAWLER_ALLOW_PRIVATE_HOSTS", "false").lower() in {"1", "true", "yes"}


def default_resolver(host: str) -> list[str]:
    try:
        infos = socket.getaddrinfo(host, None, proto=socket.IPPROTO_TCP)
    except socket.gaierror as exc:
        raise UnsafeUrlError(f"DNS resolution failed for {host}") from exc
    return sorted({info[4][0] for info in infos})


def is_public_ip(ip: str) -> bool:
    try:
        addr = ipaddress.ip_address(ip.split("%")[0])
    except ValueError:
        return False
    if isinstance(addr, ipaddress.IPv6Address) and addr.ipv4_mapped:
        addr = addr.ipv4_mapped
    if (
        addr.is_private
        or addr.is_loopback
        or addr.is_link_local
        or addr.is_multicast
        or addr.is_reserved
        or addr.is_unspecified
        or not addr.is_global
    ):
        return False
    return not any(addr in net for net in EXTRA_BLOCKED_NETWORKS if net.version == addr.version)


def normalize_url(url: str, base: str | None = None) -> str:
    """Mirror of App\\Support\\Url::normalize in Laravel (fragment dropped)."""
    from urllib.parse import urljoin

    if base:
        url = urljoin(base, url)
    parts = urlsplit(url.strip())
    scheme = parts.scheme.lower()
    host = (parts.hostname or "").lower().rstrip(".")
    port = parts.port
    netloc = host
    if port and not ((scheme == "http" and port == 80) or (scheme == "https" and port == 443)):
        netloc = f"{host}:{port}"
    path = parts.path or "/"
    while "//" in path:
        path = path.replace("//", "/")
    return urlunsplit((scheme, netloc, path, parts.query, ""))


def site_host(host: str) -> str:
    host = host.lower().rstrip(".")
    return host[4:] if host.startswith("www.") else host


def validate_url(url: str, resolver: Resolver = default_resolver) -> str:
    """Raise UnsafeUrlError unless url is a public http(s) URL. Returns the normalized URL."""
    parts = urlsplit(url)
    if parts.scheme.lower() not in ALLOWED_SCHEMES:
        raise UnsafeUrlError(f"Scheme not allowed: {parts.scheme or '(none)'}")
    if parts.username or parts.password:
        raise UnsafeUrlError("Credentials in URL are not allowed")
    host = (parts.hostname or "").lower().rstrip(".")
    if not host:
        raise UnsafeUrlError("Missing host")

    if allow_private_hosts():
        return normalize_url(url)

    if host in BLOCKED_HOSTNAMES or host.endswith(BLOCKED_SUFFIXES):
        raise UnsafeUrlError(f"Host not allowed: {host}")
    try:
        ipaddress.ip_address(host)
        ips = [host]
    except ValueError:
        if "." not in host:
            raise UnsafeUrlError(f"Single-label host not allowed: {host}")
        ips = resolver(host)
    if not ips:
        raise UnsafeUrlError(f"No addresses for {host}")
    for ip in ips:
        if not is_public_ip(ip):
            raise UnsafeUrlError(f"{host} resolves to a non-public address")
    return normalize_url(url)

import aiohttp
import asyncio
import re
from urllib.parse import urljoin, quote


# --------------------------------------------------------
# ZIPDISK DETECTOR
# --------------------------------------------------------
def is_zipdisk(url, html):
    u = url.lower()

    if any(x in u for x in ["workers.dev", "ddl", "cloudserver", "zipdisk"]):
        return True
    if "zipdisk" in html.lower():
        return True
    if re.search(r"ddl\d+\.", u):
        return True
    if re.search(r"/[0-9a-f]{40,}/", u):
        return True
    if u.endswith(".zip") and "workers.dev" in u:
        return True

    return False


# --------------------------------------------------------
def normalize_hubcloud(url):
    return re.sub(r"hubcloud\.(one|fyi)", "hubcloud.foo", url)


def extract_links(html):
    return re.findall(r'href=[\'"]([^\'"]+)[\'"]', html)


def clean_url(url):
    try:
        return quote(url, safe=":/?=&%.-_A-Za-z0-9")
    except:
        return url


# --------------------------------------------------------
# PIXELDRAIN
# --------------------------------------------------------
async def extract_pixeldrain_zip(session, pixel_url):
    try:
        m = re.search(r"/u/([A-Za-z0-9]+)", pixel_url)
        if not m:
            return None, []

        fid = m.group(1)
        api = f"https://pixeldrain.dev/api/file/{fid}/info/zip"

        async with session.get(api, headers={"User-Agent": "Mozilla/5.0"}) as r:
            data = await r.json()

        episodes = []
        base = f"https://pixeldrain.dev/api/file/{fid}/info/zip"

        folder = ""
        if data["children"] and data["children"][0]["type"] == "directory":
            folder = data["children"][0]["name"] + "/"

        full_url = None
        if folder:
            full_url = f"{base}/{folder}"

        def walk(path, tree):
            for item in tree:
                if item["type"] == "file":
                    episodes.append(f"{base}/{path}{item['name']}")
                else:
                    walk(path + item["name"] + "/", item["children"])

        walk("", data["children"])

        return full_url, episodes

    except:
        return None, []


# --------------------------------------------------------
# RESOLVERS
# --------------------------------------------------------
async def resolve_10gbps_chain(session, url):
    try:
        async with session.get(url, headers={"User-Agent": "Mozilla/5.0"}, allow_redirects=True) as r:
            final = str(r.url)

        m = re.search(r"link=([^&]+)", final)
        if m:
            return m.group(1)
    except:
        return None

    return None


async def resolve_trs(session, url):
    try:
        async with session.get(url, headers={"User-Agent": "Mozilla/5.0"}, allow_redirects=True) as r:
            return str(r.url)
    except:
        return url


# --------------------------------------------------------
def extract_trs_links(html):
    trs = set()

    trs.update(re.findall(r"window\.location\.href\s*=\s*'([^']*trs\.php[^']*)'", html))
    trs.update(re.findall(r'href=[\'"]([^\'"]*trs\.php[^\'"]*)[\'"]', html))
    trs.update(re.findall(r"(https?://[^\s\"']*trs\.php[^\s\"']*)", html))

    xs_matches = re.findall(r"trs\.php\?xs=[A-Za-z0-9=]+", html)
    for x in xs_matches:
        trs.add("https://hubcloud.foo/re/" + x)

    return list(trs)


def extract_special_links(html):

    patterns = {
        "fsl_v2": r"https://cdn\.fsl-buckets\.life/[^\s\"']+\?token=[A-Za-z0-9_]+",
        "fsl_r2": r"https://[A-Za-z0-9\.\-]+\.r2\.dev/[^\s\"']+\?token=[A-Za-z0-9_]+",
        "pixel_alt": r"https://pixel\.hubcdn\.fans/\?id=[A-Za-z0-9:]+",
        "pixeldrain": r"https://pixeldrain\.dev/u/[A-Za-z0-9]+",
        "zipdisk": r"https://[A-Za-z0-9\.\-]+workers\.dev/[^\s\"']+\.zip",
        "megaserver": r"https://mega\.blockxpiracy\.net/cs/g\?[^\s\"']+",
    }

    found = []

    for name, pattern in patterns.items():
        for link in re.findall(pattern, html):
            found.append((name, link))

    return found


# --------------------------------------------------------
# MAIN SCRAPER
# --------------------------------------------------------
async def extract_hubcloud_links(session, target):

    target = normalize_hubcloud(target)

    async with session.get(target, headers={"User-Agent": "Mozilla/5.0"}) as r:
        html = await r.text()
        final_url = str(r.url)

    title = re.search(r"<title>(.*?)</title>", html)
    title = title.group(1) if title else "Unknown"

    size_match = re.search(r"File Size<i[^>]*>(.*?)</i>", html)
    size = re.sub(r"<.*?>", "", size_match.group(1)).strip() if size_match else "Unknown"

    hrefs = extract_links(html)

    m = re.search(r'(https://love\.stranger-things\.buzz[^"]+)', html)
    if m:
        hrefs.append(m.group(1))

    m = re.search(r'(https://gpdl\.hubcdn\.fans[^"]+)', html)
    if m:
        hrefs.append(m.group(1))

    m = re.search(r'https://pixeldrain\.dev/u/[A-Za-z0-9]+', html)
    if m:
        hrefs.append(m.group(0))

    trs_links = extract_trs_links(html)
    hrefs.extend(trs_links)

    special_links = extract_special_links(html)
    for name, link in special_links:
        hrefs.append(link)

    mirrors = []
    for link in hrefs:

        if not link.startswith("http"):
            continue

        link = clean_url(link)

        if is_zipdisk(link, html):
            mirrors.append({"label": "zipdiskserver", "url": link})
            continue

        if "pixeldrain.dev/u" in link:
            mirrors.append({"label": "pixelserver", "url": link})
            continue

        if "fsl-buckets" in link:
            mirrors.append({"label": "FSL-V2", "url": link})
            continue

        if "r2.dev" in link:
            mirrors.append({"label": "FSL-R2", "url": link})
            continue

        if "pixel.hubcdn.fans" in link:
            mirrors.append({"label": "Pixel-Alt", "url": link})
            continue

        if "blockxpiracy" in link:
            mirrors.append({"label": "MegaServer", "url": link})
            continue

        if "stranger-things" in link:
            mirrors.append({"label": "FSL", "url": link})
            continue

        if "gpdl.hubcdn.fans" in link:
            mirrors.append({"label": "10Gbps", "url": link})

            direct = await resolve_10gbps_chain(session, link)
            if direct:
                mirrors.append({"label": "10Gbps-Direct", "url": direct})

            continue

        if "trs.php" in link:
            final_trs = await resolve_trs(session, link)
            mirrors.append({"label": "TRS", "url": final_trs})
            continue

    out = []
    seen = set()
    for m in mirrors:
        if m["url"] not in seen:
            seen.add(m["url"])
            out.append(m)

    return {
        "title": title,
        "size": size,
        "main_link": target,
        "mirrors": out
    }


# --------------------------------------------------------
# VERCEL HANDLER (IMPORTANT)
# --------------------------------------------------------
async def handler(request):
    url = request.query.get("url")
    if not url:
        return {"error": "Missing ?url parameter"}

    async with aiohttp.ClientSession() as session:
        result = await extract_hubcloud_links(session, url)

    return result

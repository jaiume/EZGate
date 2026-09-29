#pragma once

#include <Arduino.h>
#include <ArduinoJson.h>

void ezgSetHeartbeatSeconds(int seconds);
void ezgApplyConfigVersionUnix(uint32_t unix);
void loraLinkBegin();
void loraLinkService();
void loraLinkApplyConfig(JsonObjectConst cfg);
void loraLinkFillRadioStats(JsonObject dest);
void loraLinkFillConnectivity(JsonObject current);
bool loraLinkHasParent();
bool loraLinkHasGatewayPeer();
bool loraLinkIsGateway();
bool loraLinkRadioOk();
bool loraLinkPortalOk();
bool loraLinkShouldDropWifi();
void loraLinkNoteWifiPortal(bool ok);
bool loraLinkSendOwnHeartbeat();
bool loraLinkSendUplinkJson(const String &json);
int loraLinkHeardCount();
bool loraLinkHeardAt(int i, int &siteIdOut, String &nameOut);
bool loraLinkSetupViaHeard(int siteId, const String &friendlyName);
const char *loraLinkRoleName();
unsigned long loraLinkLastRadioOkMs();
int loraLinkHops();
